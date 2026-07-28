<?php

namespace App\Services\Git;

use App\Contracts\GitProvider;
use App\Exceptions\Server\GitProviderException;

/**
 * Resolves the GitProvider strategy for a provider key and describes the
 * connect form, so the frontend renders it from data rather than hardcoding
 * a field set per provider (they genuinely differ — Bitbucket needs a
 * workspace, GitLab can point at a self-hosted host).
 */
class GitProviderManager
{
    /**
     * @return array<int, string>
     */
    public function providerNames(): array
    {
        return array_keys((array) config('server.git.providers', []));
    }

    public function supports(string $provider): bool
    {
        return in_array($provider, $this->providerNames(), true);
    }

    public function driver(string $provider): GitProvider
    {
        if (! $this->supports($provider)) {
            throw GitProviderException::unsupportedProvider($provider);
        }

        /** @var class-string<GitProvider> $class */
        $class = (string) config("server.git.providers.{$provider}.driver");

        return app($class);
    }

    /**
     * The connect-form schema: which fields each provider needs, localized.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        return array_map(function (string $provider) {
            $fields = (array) config("server.git.providers.{$provider}.fields", []);

            return [
                'name' => $provider,
                'title' => __("git.providers.{$provider}"),
                'token_help' => __("git.token_help.{$provider}"),
                'fields' => array_map(fn (string $field, array $spec) => [
                    'name' => $field,
                    'label' => __("git.fields.{$field}"),
                    'required' => (bool) ($spec['required'] ?? false),
                    'type' => (string) ($spec['type'] ?? 'text'),
                ], array_keys($fields), array_values($fields)),
            ];
        }, $this->providerNames());
    }
}
