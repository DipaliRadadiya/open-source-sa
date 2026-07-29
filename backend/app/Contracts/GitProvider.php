<?php

namespace App\Contracts;

use App\Models\GitAccount;
use Illuminate\Support\Carbon;

/**
 * A git hosting provider the panel can connect to with a token. Adding a
 * provider means adding one implementation and one config entry — nothing
 * else in the feature changes.
 *
 * Implementations must treat every upstream byte as adversarial: map only
 * allow-listed fields out of the vendor JSON, never hand a raw payload to a
 * model.
 */
interface GitProvider
{
    /**
     * Provider key (github | gitlab | bitbucket).
     */
    public function key(): string;

    /**
     * The username git must pair the token with over HTTPS. Each provider
     * expects a different fixed value, and the wrong one fails authentication
     * even with a perfectly good token.
     */
    public function credentialUsername(): string;

    /**
     * Prove the credential works and return the account identity.
     *
     * @return array{identifier: string, scopes: array<int, string>}
     */
    public function verify(GitAccount $account): array;

    /**
     * Live health of the stored credential, asked of the provider right now —
     * nothing is cached or persisted, because a token can be revoked at the
     * provider at any moment.
     *
     * Never throws: a dead token is an answer, not an error. `expires_at` is
     * null wherever the provider does not expose one (always, for Bitbucket).
     *
     * @return array{status: 'valid'|'invalid'|'unknown', expires_at: ?Carbon}
     */
    public function status(GitAccount $account): array;

    /**
     * Repositories the credential can reach.
     *
     * @return array{repositories: array<int, array<string, mixed>>, page: int, has_more: bool}
     */
    public function repositories(GitAccount $account, ?string $search, int $page): array;

    /**
     * Branches of one repository, addressed by its full name (owner/repo).
     *
     * @return array<int, array<string, mixed>>
     */
    public function branches(GitAccount $account, string $repository): array;
}
