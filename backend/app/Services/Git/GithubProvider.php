<?php

namespace App\Services\Git;

use App\Models\GitAccount;
use Illuminate\Support\Facades\Cache;

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

    /**
     * GitHub pairs a token with the literal `x-access-token` username.
     */
    public function credentialUsername(): string
    {
        return 'x-access-token';
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

    /**
     * GitHub reports a token's expiry in a response header, but only for
     * tokens that have one — classic tokens set to "no expiration" and some
     * fine-grained tokens send nothing, which is not a problem.
     */
    public function status(GitAccount $account): array
    {
        $probe = $this->probe($account, fn ($client) => $client->get('/user'));

        return [
            'status' => $probe['status'],
            'expires_at' => $probe['response'] === null
                ? null
                : $this->parseExpiry($probe['response']->header('github-authentication-token-expiration')),
        ];
    }

    public function repositories(GitAccount $account, ?string $search, int $page): array
    {
        if ($search !== null && $search !== '') {
            return $this->searchEverywhere($account, $search, $page);
        }

        $response = $this->send($account, fn ($client) => $client->get('/user/repos', [
            'per_page' => $this->perPage(),
            'page' => $page,
            'sort' => 'updated',
            'affiliation' => 'owner,collaborator,organization_member',
        ]));

        $items = (array) $response->json();

        return [
            'repositories' => array_values(array_map(fn (array $repo) => $this->mapRepository($repo), $items)),
            'page' => $page,
            'has_more' => count($items) >= $this->perPage(),
        ];
    }

    /**
     * Search across every repository the account can see, not one page.
     *
     * GitHub's /user/repos has no name filter, and this used to filter the
     * one page it had fetched — so with 54 repositories, one on page 2 was
     * never found (junior re-test #15). GitHub's search API would filter on
     * their side but only covers what a `user:`/`org:` qualifier names, which
     * misses repositories the account collaborates on. So the whole list is
     * fetched, 100 at a time, capped, and kept for two minutes per account so
     * typing a search does not refetch it on every keystroke. Only the mapped
     * fields are cached; no token or response body is.
     *
     * @return array{repositories: array<int, array<string, mixed>>, page: int, has_more: bool}
     */
    private function searchEverywhere(GitAccount $account, string $search, int $page): array
    {
        $all = Cache::remember(
            'git:github:repositories:'.$account->id.':'.$account->updated_at?->getTimestamp(),
            (int) config('server.git.search_cache_seconds', 120),
            fn (): array => $this->allRepositories($account),
        );

        $matches = array_values(array_filter(
            $all,
            fn (array $repo) => str_contains(strtolower($repo['full_name']), strtolower($search)),
        ));

        $perPage = $this->perPage();

        return [
            'repositories' => array_slice($matches, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'has_more' => count($matches) > $page * $perPage,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allRepositories(GitAccount $account): array
    {
        $all = [];
        $pages = max(1, (int) config('server.git.search_max_pages', 10));

        for ($page = 1; $page <= $pages; $page++) {
            $batch = (array) $this->send($account, fn ($client) => $client->get('/user/repos', [
                'per_page' => 100,
                'page' => $page,
                'sort' => 'updated',
                'affiliation' => 'owner,collaborator,organization_member',
            ]))->json();

            foreach ($batch as $repo) {
                $all[] = $this->mapRepository((array) $repo);
            }

            if (count($batch) < 100) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $repo
     * @return array<string, mixed>
     */
    private function mapRepository(array $repo): array
    {
        return $this->repository(
            (string) ($repo['full_name'] ?? ''),
            (string) ($repo['name'] ?? ''),
            (bool) ($repo['private'] ?? false),
            $repo['default_branch'] ?? null,
            $repo['html_url'] ?? null,
        );
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

    public function createWebhook(GitAccount $account, string $repository, string $url, string $secret): string
    {
        $response = $this->send($account, fn ($client) => $client->post("/repos/{$repository}/hooks", [
            'name' => 'web',
            ...$this->hookBody($url, $secret),
        ]));

        return (string) $response->json('id');
    }

    public function updateWebhook(GitAccount $account, string $repository, string $id, string $url, string $secret): bool
    {
        $response = $this->send(
            $account,
            fn ($client) => $client->patch("/repos/{$repository}/hooks/".rawurlencode($id), $this->hookBody($url, $secret)),
            missingOk: true,
        );

        return $response->status() !== 404;
    }

    public function deleteWebhook(GitAccount $account, string $repository, string $id): void
    {
        $this->send($account, fn ($client) => $client->delete("/repos/{$repository}/hooks/".rawurlencode($id)), missingOk: true);
    }

    /**
     * Push events only, as JSON, signed with the secret. TLS verification
     * stays on: a panel without a valid certificate should fail loudly in the
     * hook's delivery log, not deploy over an unverified connection.
     *
     * @return array<string, mixed>
     */
    private function hookBody(string $url, string $secret): array
    {
        return [
            'active' => true,
            'events' => ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ];
    }
}
