<?php

namespace App\Services\Git\Webhooks;

use App\Contracts\GitWebhook;
use App\Exceptions\Server\GitProviderException;

/**
 * Resolves the webhook verifier for a provider key, and describes each
 * provider's setup so the frontend can render the instructions from data
 * instead of hardcoding three sets of steps that then drift from reality.
 */
class WebhookManager
{
    /**
     * @return array<int, string>
     */
    public function providerNames(): array
    {
        return array_keys((array) config('server.webhooks.providers', []));
    }

    public function supports(string $provider): bool
    {
        return in_array($provider, $this->providerNames(), true);
    }

    public function driver(string $provider): GitWebhook
    {
        if (! $this->supports($provider)) {
            throw GitProviderException::unsupportedProvider($provider);
        }

        /** @var class-string<GitWebhook> $class */
        $class = (string) config("server.webhooks.providers.{$provider}.driver");

        return app($class);
    }

    /**
     * What the user has to do at each provider, and crucially **which way the
     * secret travels**: the panel generates one for GitHub and Bitbucket, but
     * GitLab's recommended signing token is minted by GitLab and shown once, so
     * there the user pastes it in here.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        return array_map(function (string $provider) {
            $driver = $this->driver($provider);

            return [
                'name' => $provider,
                'title' => __("git.providers.{$provider}"),
                // 'generate' = we mint it and the user copies it out;
                // 'paste' = the provider mints it and the user copies it in;
                // 'either' = both, and the UI should offer the choice.
                'secret_source' => $driver->secretSource(),
                'instructions' => __("webhook.instructions.{$provider}"),
            ];
        }, $this->providerNames());
    }
}
