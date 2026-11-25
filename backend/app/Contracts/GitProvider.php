<?php

namespace App\Contracts;

use App\Exceptions\Server\GitProviderException;
use App\Models\GitAccount;
use Illuminate\Support\Carbon;

/**
 * A git hosting provider the panel can connect to with a token. A new provider is one
 * implementation plus one config entry.
 *
 * Treat every upstream byte as adversarial: map only allow-listed fields out of the vendor
 * JSON, never hand a raw payload to a model.
 */
interface GitProvider
{
    /**
     * Provider key (github | gitlab | bitbucket).
     */
    public function key(): string;

    /**
     * The username git must pair the token with over HTTPS; each provider expects a different
     * fixed value.
     */
    public function credentialUsername(): string;

    /**
     * Prove the credential works and return the account identity.
     *
     * @return array{identifier: string, scopes: array<int, string>}
     */
    public function verify(GitAccount $account): array;

    /**
     * Live health of the stored credential, asked of the provider now (never cached: a token
     * can be revoked at any moment).
     *
     * Never throws: a dead token is an answer. `expires_at` is null where the provider does not
     * expose one (always, for Bitbucket).
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

    /**
     * Add a push webhook to the repository and return the provider's id for it.
     *
     * @throws GitProviderException
     */
    public function createWebhook(GitAccount $account, string $repository, string $url, string $secret): string;

    /**
     * Point an existing webhook at the URL and secret again. False when the provider no longer
     * has it (removed by hand), so the caller can create a new one.
     *
     * @throws GitProviderException
     */
    public function updateWebhook(GitAccount $account, string $repository, string $id, string $url, string $secret): bool;

    /**
     * Remove it. Already gone counts as removed.
     *
     * @throws GitProviderException
     */
    public function deleteWebhook(GitAccount $account, string $repository, string $id): void;
}
