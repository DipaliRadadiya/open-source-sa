<?php

namespace App\Actions\Server\Application;

use App\Exceptions\Server\Application\WebhookConfigurationException;
use App\Exceptions\Server\GitProviderException;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Git\WebhookRegistrar;
use App\Services\Git\Webhooks\WebhookManager;
use Illuminate\Support\Str;

/**
 * Turns deploy-on-push on or off for one application.
 *
 * The identifier and the secret are kept when the webhook is disabled, so
 * switching it back on does not invalidate what the user already pasted into
 * their repository settings. Rotating is the explicit way to invalidate.
 */
class ConfigureApplicationWebhook
{
    /**
     * How the last `execute()` that switched deploy-on-push on left the repository's webhook:
     * `{status: registered|manual, reason}`. Null after switching it off.
     *
     * @var array{status: string, reason: ?string}|null
     */
    public ?array $registration = null;

    public function __construct(
        private WebhookManager $webhooks,
        private ActivityLogger $activityLogger,
        private WebhookRegistrar $registrar,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws WebhookConfigurationException
     */
    public function execute(Application $application, array $data): Application
    {
        if ($application->site_type !== 'git') {
            throw WebhookConfigurationException::notAGitApplication();
        }

        $this->registration = null;

        if (! ($data['enabled'] ?? false)) {
            // Remove the hook the panel added; one added by hand is left alone. A refusal is
            // reported (e.g. a Bitbucket token without `delete:webhook`), not swallowed.
            if (! $this->registrar->unregister($application)) {
                $this->registration = ['status' => WebhookRegistrar::REMOVAL_REFUSED, 'reason' => 'removal_refused'];
            }

            $application->forceFill(['webhook_enabled' => false])->save();

            $this->activityLogger->log('application.webhook_disabled', $application, [
                'name' => $application->name,
            ]);

            return $application->refresh();
        }

        $provider = (string) ($data['provider'] ?? $application->gitAccount?->provider);

        // Also checked here: an unknown provider would leave a webhook enabled that no verifier
        // can handle, so deliveries would 500.
        if (! $this->webhooks->supports($provider)) {
            throw GitProviderException::unsupportedProvider($provider);
        }

        $previous = $application->webhook_secret;

        $secret = $this->resolveSecret(
            $application,
            $data['secret'] ?? null,
            (bool) ($data['rotate'] ?? false),
        );

        // Only a replacement is a rotation; a first enable invalidates nothing.
        $rotated = filled($previous) && $secret !== $previous;

        $application->forceFill([
            'webhook_enabled' => true,
            'webhook_provider' => $provider,
            // Kept across disable/enable so the URL in the repository settings stays valid.
            'webhook_identifier' => $application->webhook_identifier ?: $this->identifier(),
            'webhook_secret' => $secret,
        ])->save();

        // After the save: the provider must get the stored secret (matters on rotation).
        $this->registration = $this->registrar->register($application);

        $this->activityLogger->log(
            $rotated ? 'application.webhook_rotated' : 'application.webhook_enabled',
            $application,
            [
                'name' => $application->name,
                'provider' => $provider,
                'registered' => $this->registration['status'] === WebhookRegistrar::REGISTERED,
            ],
        );

        return $application->refresh();
    }

    /**
     * A pasted secret wins; otherwise the stored one is kept, unless the caller
     * asked to rotate or there is nothing stored yet.
     *
     * Every provider can get a generated secret; GitLab gets its legacy plaintext token (not the
     * signing token), and `webhook.verification` in the response says which check is in force.
     */
    private function resolveSecret(Application $application, ?string $supplied, bool $rotate): string
    {
        if (filled($supplied)) {
            return (string) $supplied;
        }

        if (! $rotate && filled($application->webhook_secret)) {
            return (string) $application->webhook_secret;
        }

        return $this->secret();
    }

    /**
     * 64 hex characters from a CSPRNG; hex survives copy-paste into provider web forms.
     */
    private function secret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The public part of the URL. Not a credential, but random so a sequential id cannot be
     * enumerated.
     */
    private function identifier(): string
    {
        return (string) Str::uuid();
    }
}
