<?php

namespace App\Actions\Server\Application;

use App\Exceptions\Server\Application\WebhookConfigurationException;
use App\Exceptions\Server\GitProviderException;
use App\Models\Application;
use App\Services\ActivityLogger;
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
    public function __construct(
        private WebhookManager $webhooks,
        private ActivityLogger $activityLogger,
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

        if (! ($data['enabled'] ?? false)) {
            $application->forceFill(['webhook_enabled' => false])->save();

            $this->activityLogger->log('application.webhook_disabled', $application, [
                'name' => $application->name,
            ]);

            return $application->refresh();
        }

        $provider = (string) ($data['provider'] ?? $application->gitAccount?->provider);

        // Checked here as well as in the FormRequest: this action is the thing
        // that stores the provider, and an unknown one would leave a webhook
        // enabled that no verifier can be resolved for — a delivery would then
        // 500 rather than being refused.
        if (! $this->webhooks->supports($provider)) {
            throw GitProviderException::unsupportedProvider($provider);
        }

        $previous = $application->webhook_secret;

        $secret = $this->resolveSecret(
            $application,
            $data['secret'] ?? null,
            (bool) ($data['rotate'] ?? false),
        );

        // Only a *replacement* is a rotation. Enabling for the first time has no
        // previous secret to replace, and reporting that as "replaced the
        // secret" would tell the user something was invalidated when nothing was.
        $rotated = filled($previous) && $secret !== $previous;

        $application->forceFill([
            'webhook_enabled' => true,
            'webhook_provider' => $provider,
            // Kept across disable/enable so the URL in the user's repository
            // settings stays valid; only ever generated once.
            'webhook_identifier' => $application->webhook_identifier ?: $this->identifier(),
            'webhook_secret' => $secret,
        ])->save();

        $this->activityLogger->log(
            $rotated ? 'application.webhook_rotated' : 'application.webhook_enabled',
            $application,
            ['name' => $application->name, 'provider' => $provider],
        );

        return $application->refresh();
    }

    /**
     * A pasted secret wins; otherwise the stored one is kept, unless the caller
     * asked to rotate or there is nothing stored yet.
     *
     * Every provider can be given a generated secret — GitLab's *signing* token
     * cannot be generated, but its legacy plaintext one can, and that is what a
     * GitLab application with no pasted secret gets. `webhook.verification` in
     * the response says which check that leaves in force, so the weaker scheme
     * is never a silent outcome.
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
     * 64 hex characters from a CSPRNG. Hex rather than the full base64 charset
     * because this gets typed into three different providers' web forms, and a
     * value that survives a copy-paste is worth more than the eight bits of
     * entropy per character it costs — 256 bits is not the constraint here.
     */
    private function secret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The public part of the URL. Not a credential — it only names the
     * application — but random anyway, because a sequential id would let anyone
     * enumerate how many sites a panel hosts.
     */
    private function identifier(): string
    {
        return (string) Str::uuid();
    }
}
