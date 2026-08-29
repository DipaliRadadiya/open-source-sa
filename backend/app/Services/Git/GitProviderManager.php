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
                    // Per-field guidance, null when the field speaks for
                    // itself. There was nowhere to put this, so what a
                    // self-hosted GitLab URL should contain and where a
                    // Bitbucket workspace name comes from were either absent
                    // or buried inside the *token's* help string — text about
                    // one field, describing another, next to neither.
                    //
                    // Keyed per provider and field: `host` means a GitLab URL
                    // and nothing else, and a shared key would eventually be
                    // asked to describe two different things at once.
                    'help' => $this->fieldHelp($provider, $field),
                ], array_keys($fields), array_values($fields)),
            ];
        }, $this->providerNames());
    }

    /**
     * Help for one field of one provider, or null when there is none.
     *
     * Absent by default rather than falling back to the key: a missing
     * translation surfacing as `git.field_help.github.token` in a form input's
     * hint is worse than no hint at all.
     */
    private function fieldHelp(string $provider, string $field): ?string
    {
        $key = "git.field_help.{$provider}.{$field}";
        $line = __($key);

        return is_string($line) && $line !== $key ? $line : null;
    }
}
