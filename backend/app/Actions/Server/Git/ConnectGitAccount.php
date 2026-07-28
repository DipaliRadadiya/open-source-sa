<?php

namespace App\Actions\Server\Git;

use App\Models\GitAccount;
use App\Services\ActivityLogger;
use App\Services\Git\GitProviderManager;

/**
 * Connect a git account — verify first, persist only on success.
 *
 * The credential is proven against the provider before anything is written,
 * so a typo can never leave a dead connection behind, and the account's
 * identity comes from the provider rather than from user input.
 */
class ConnectGitAccount
{
    public function __construct(
        private GitProviderManager $manager,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): GitAccount
    {
        $account = new GitAccount([
            'provider' => $data['provider'],
            'label' => $data['label'],
            'token' => $data['token'],
            'host' => $data['host'] ?? null,
            'workspace' => $data['workspace'] ?? null,
        ]);

        // Throws (422/502) before we touch the database.
        $identity = $this->manager->driver($account->provider)->verify($account);

        $account->fill([
            'identifier' => $identity['identifier'],
            'scopes' => $identity['scopes'],
            'last_verified_at' => now(),
        ])->save();

        $this->activityLogger->log('git_account.connected', $account, [
            'provider' => $account->provider,
            'label' => $account->label,
        ]);

        return $account;
    }
}
