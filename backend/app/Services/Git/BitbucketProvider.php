<?php

namespace App\Services\Git;

use App\Models\GitAccount;

/**
 * Bitbucket, via a scoped Access Token (workspace, project, or repository
 * level) — the modern replacement for App Passwords.
 *
 * These tokens authenticate as the *token*, not as a user, so `/2.0/user`
 * answers 401 for them. Identity and verification therefore go through the
 * workspace's repository listing, which is also exactly what a repository-
 * scoped token can see: a token scoped to one repository verifies fine and
 * lists that one repository. That is intentional — the user picks the access
 * breadth when they mint the token, and the panel reflects it rather than
 * demanding workspace-wide access.
 */
class BitbucketProvider extends AbstractGitProvider
{
    public function key(): string
    {
        return 'bitbucket';
    }

    public function verify(GitAccount $account): array
    {
        // A cheap call that any scoped token can make within its workspace.
        $this->send($account, fn ($client) => $client->get("/2.0/repositories/{$account->workspace}", [
            'pagelen' => 1,
        ]));

        return [
            'identifier' => (string) $account->workspace,
            'scopes' => [],
        ];
    }

    public function repositories(GitAccount $account, ?string $search, int $page): array
    {
        $query = [
            'pagelen' => $this->perPage(),
            'page' => $page,
            'sort' => '-updated_on',
        ];

        if ($search !== null && $search !== '') {
            // Bitbucket's filter language; the value is quoted, and the
            // request builder encodes it.
            $query['q'] = 'name~"'.str_replace('"', '', $search).'"';
        }

        $response = $this->send($account, fn ($client) => $client->get("/2.0/repositories/{$account->workspace}", $query));

        $items = (array) $response->json('values', []);

        return [
            'repositories' => array_values(array_map(fn (array $repo) => $this->repository(
                (string) ($repo['full_name'] ?? ''),
                (string) ($repo['name'] ?? ''),
                (bool) ($repo['is_private'] ?? true),
                $repo['mainbranch']['name'] ?? null,
                $repo['links']['html']['href'] ?? null,
            ), $items)),
            'page' => $page,
            'has_more' => $response->json('next') !== null,
        ];
    }

    public function branches(GitAccount $account, string $repository): array
    {
        $response = $this->send($account, fn ($client) => $client->get("/2.0/repositories/{$repository}/refs/branches", [
            'pagelen' => 100,
        ]));

        return array_values(array_map(fn (array $branch) => [
            'name' => (string) ($branch['name'] ?? ''),
            'protected' => false, // Bitbucket exposes branch restrictions separately
        ], (array) $response->json('values', [])));
    }
}
