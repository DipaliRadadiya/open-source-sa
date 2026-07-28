<?php

namespace App\Services\Git;

use App\Models\GitAccount;

/**
 * GitHub, via a personal access token (classic or fine-grained). The token's
 * granted scopes come back in the `x-oauth-scopes` response header for
 * classic tokens; fine-grained tokens report none, which is expected.
 */
class GithubProvider extends AbstractGitProvider
{
    public function key(): string
    {
        return 'github';
    }

    public function verify(GitAccount $account): array
    {
        $response = $this->send($account, fn ($client) => $client->get('/user'));

        $scopes = array_values(array_filter(array_map(
            trim(...),
            explode(',', $response->header('x-oauth-scopes')),
        )));

        return [
            'identifier' => (string) ($response->json('login') ?? ''),
            'scopes' => $scopes,
        ];
    }

    public function repositories(GitAccount $account, ?string $search, int $page): array
    {
        $response = $this->send($account, fn ($client) => $client->get('/user/repos', [
            'per_page' => $this->perPage(),
            'page' => $page,
            'sort' => 'updated',
            'affiliation' => 'owner,collaborator,organization_member',
        ]));

        $items = (array) $response->json();

        // GitHub's /user/repos has no server-side name filter (the search API
        // is a separate, rate-limited endpoint), so narrow the page here.
        if ($search !== null && $search !== '') {
            $items = array_filter(
                $items,
                fn (array $repo) => str_contains(strtolower((string) ($repo['full_name'] ?? '')), strtolower($search)),
            );
        }

        return [
            'repositories' => array_values(array_map(fn (array $repo) => $this->repository(
                (string) ($repo['full_name'] ?? ''),
                (string) ($repo['name'] ?? ''),
                (bool) ($repo['private'] ?? false),
                $repo['default_branch'] ?? null,
                $repo['html_url'] ?? null,
            ), $items)),
            'page' => $page,
            'has_more' => count((array) $response->json()) >= $this->perPage(),
        ];
    }

    public function branches(GitAccount $account, string $repository): array
    {
        $response = $this->send($account, fn ($client) => $client->get("/repos/{$repository}/branches", [
            'per_page' => 100,
        ]));

        return array_values(array_map(fn (array $branch) => [
            'name' => (string) ($branch['name'] ?? ''),
            'protected' => (bool) ($branch['protected'] ?? false),
        ], (array) $response->json()));
    }
}
