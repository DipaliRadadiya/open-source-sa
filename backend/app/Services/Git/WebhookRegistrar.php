<?php

namespace App\Services\Git;

use App\Exceptions\Server\GitProviderException;
use App\Models\Application;
use App\Services\Git\Webhooks\GitlabWebhook;
use App\Services\Git\Webhooks\WebhookManager;
use Illuminate\Support\Facades\Log;

/**
 * Adds, updates and removes the deploy webhook in the repository itself.
 *
 * Deploy-on-push used to hand the user a URL and a secret to paste into
 * GitHub, GitLab or Bitbucket. The panel already holds a token for the
 * account the site deploys from, so it now does that step itself — and
 * undoes it when deploy-on-push is switched off or the site is deleted.
 *
 * Never fatal. Everything here is an improvement on a manual step that still
 * works, so anything that stops it — no account, a token without hook
 * permission, a panel the provider could not reach — falls back to the manual
 * step with the reason, rather than failing the switch the user pressed.
 *
 * The only host ever called is the account's own provider API (its
 * configured base URL, through AbstractGitProvider's hardened client). The URL
 * a provider is told to deliver to is the panel's own, never user input.
 */
class WebhookRegistrar
{
    public const REGISTERED = 'registered';

    public const MANUAL = 'manual';

    /** Deployed from a public URL, not a connected account: no token to act with. */
    public const NO_ACCOUNT = 'no_account';

    /** A GitLab signing token: GitLab mints it, so the API cannot set it. */
    public const SIGNING_TOKEN = 'signing_token';

    /** The panel's address is not one the provider could deliver to. */
    public const NOT_PUBLIC = 'not_public';

    /** The provider refused or could not be reached; usually a token without hook permission. */
    public const PROVIDER_REFUSED = 'provider_refused';

    public function __construct(
        private GitProviderManager $providers,
        private WebhookManager $webhooks,
    ) {}

    /**
     * Make the repository's webhook match the application: created if the
     * panel has none on record, updated otherwise (a rotated secret), and
     * created again if it was deleted by hand.
     *
     * @return array{status: string, reason: ?string}
     */
    public function register(Application $application): array
    {
        $account = $application->gitAccount;
        $secret = (string) $application->webhook_secret;

        if ($account === null || blank($application->repository) || $application->webhook_provider !== $account->provider) {
            return $this->manual(self::NO_ACCOUNT);
        }

        $verifier = $this->webhooks->driver($account->provider);

        if ($verifier instanceof GitlabWebhook && $verifier->isSigningToken($secret)) {
            return $this->manual(self::SIGNING_TOKEN);
        }

        $url = $application->webhookUrl();

        if ($url === null || ! $this->publiclyReachable($url)) {
            return $this->manual(self::NOT_PUBLIC);
        }

        $provider = $this->providers->driver($account->provider);
        $repository = (string) $application->repository;

        try {
            $id = $application->webhook_remote_id;

            if ($id === null || ! $provider->updateWebhook($account, $repository, $id, $url, $secret)) {
                $id = $provider->createWebhook($account, $repository, $url, $secret);
            }
        } catch (GitProviderException $exception) {
            $this->warn($application, 'register', $exception);

            return $this->manual(self::PROVIDER_REFUSED);
        }

        $application->forceFill(['webhook_remote_id' => $id])->save();

        return ['status' => self::REGISTERED, 'reason' => null];
    }

    /**
     * Remove the webhook the panel registered, if it registered one. The id is
     * kept when removal fails, so switching deploy-on-push back on updates the
     * same hook rather than adding a second one beside it.
     */
    public function unregister(Application $application): bool
    {
        $account = $application->gitAccount;
        $id = $application->webhook_remote_id;

        if ($id === null || $account === null || blank($application->repository)) {
            return $id === null;
        }

        try {
            $this->providers->driver($account->provider)->deleteWebhook($account, (string) $application->repository, $id);
        } catch (GitProviderException $exception) {
            $this->warn($application, 'unregister', $exception);

            return false;
        }

        $application->forceFill(['webhook_remote_id' => null])->save();

        return true;
    }

    /**
     * Whether a provider on the internet could deliver to this URL: a host
     * that resolves only to public addresses. `localhost`, a private or
     * reserved address (a panel reached over a LAN or a VPN) would produce a
     * hook whose every delivery fails, which is worse than the manual step
     * because it looks done.
     */
    private function publiclyReachable(string $url): bool
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if ($host === '' || str_ends_with(strtolower($host), 'localhost')) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /** @return array{status: string, reason: ?string} */
    private function manual(string $reason): array
    {
        return ['status' => self::MANUAL, 'reason' => $reason];
    }

    private function warn(Application $application, string $op, GitProviderException $exception): void
    {
        // Kind and reference only: never the token, the secret or a body.
        Log::channel('server-ops')->warning('git webhook '.$op.' failed', [
            'feature' => 'git_webhook',
            'op' => $op,
            'application' => $application->id,
            'kind' => $exception->kind,
            'reference' => $exception->reference,
        ]);
    }
}
