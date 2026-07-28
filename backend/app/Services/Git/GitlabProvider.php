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

    public function verify(GitAccount $account): array
    {
        $response = $this->send($account, fn ($client) => $client->get('/api/v4/user'));

        return [
            'identifier' => (string) ($response->json('username') ?? ''),
            'scopes' => [],
        ];
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
