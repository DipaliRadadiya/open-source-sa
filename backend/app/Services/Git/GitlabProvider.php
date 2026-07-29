<?php

namespace App\Services\Git;

use App\Models\GitAccount;

/**
 * GitLab, via a personal access token. Works against gitlab.com or a
 * self-hosted instance (the account's `host`). Project paths are URL-encoded
 * because GitLab addresses projects by `group/subgroup/project`.
 */
class GitlabProvider extends AbstractGitProvider
{
    public function key(): string
    {
        return 'gitlab';
    }

    /**
     * GitLab expects `oauth2` as the username for token auth.
     */
    public function credentialUsername(): string
    {
        return 'oauth2';
    }

    public function verify(GitAccount $account): array
    {
        $response = $this->send($account, fn ($client) => $client->get('/api/v4/user'));

        return [
            'identifier' => (string) ($response->json('username') ?? ''),
            'scopes' => [],
        ];
    }

    /**
     * GitLab is the only provider that will tell us when a token expires:
     * `/personal_access_tokens/self` reports `expires_at`, `active` and
     * `revoked`.
     *
     * That endpoint needs the `api`/`read_api` scope though, so a token
     * created with only `read_repository` is refused there — which says
     * nothing about the token's health. On that refusal we fall back to
     * `/user`: if it answers, the token is fine and we simply do not know
     * its expiry.
     */
    public function status(GitAccount $account): array
    {
        $probe = $this->probe($account, fn ($client) => $client->get('/api/v4/personal_access_tokens/self'));

        if ($probe['status'] === 'valid') {
            $token = (array) $probe['response']->json();

            // An inactive or revoked token can still be described by the API.
            $dead = ($token['revoked'] ?? false) === true || ($token['active'] ?? true) === false;

            return [
                'status' => $dead ? 'invalid' : 'valid',
                'expires_at' => $this->parseExpiry($token['expires_at'] ?? null),
            ];
        }

        if ($probe['status'] === 'unknown') {
            return ['status' => 'unknown', 'expires_at' => null];
        }

        // Refused: either a dead token, or a live one without `read_api`.
        $fallback = $this->probe($account, fn ($client) => $client->get('/api/v4/user'));

        return ['status' => $fallback['status'], 'expires_at' => null];
    }

    public function repositories(GitAccount $account, ?string $search, int $page): array
    {
        $response = $this->send($account, fn ($client) => $client->get('/api/v4/projects', array_filter([
            'membership' => 'true',
            'per_page' => $this->perPage(),
            'page' => $page,
            'order_by' => 'last_activity_at',
            'search' => $search,
        ], fn ($value) => $value !== null && $value !== '')));

        $items = (array) $response->json();

        return [
            'repositories' => array_values(array_map(fn (array $project) => $this->repository(
                (string) ($project['path_with_namespace'] ?? ''),
                (string) ($project['name'] ?? ''),
                ($project['visibility'] ?? 'private') !== 'public',
                $project['default_branch'] ?? null,
                $project['web_url'] ?? null,
            ), $items)),
            'page' => $page,
            'has_more' => count($items) >= $this->perPage(),
        ];
    }

    public function branches(GitAccount $account, string $repository): array
    {
        $project = rawurlencode($repository);

        $response = $this->send($account, fn ($client) => $client->get("/api/v4/projects/{$project}/repository/branches", [
            'per_page' => 100,
        ]));

        return array_values(array_map(fn (array $branch) => [
            'name' => (string) ($branch['name'] ?? ''),
            'protected' => (bool) ($branch['protected'] ?? false),
        ], (array) $response->json()));
    }
}
