<?php

namespace App\Actions\Server\Git;

use App\Models\GitAccount;
use App\Services\Git\GitProviderManager;

/**
 * Re-check a stored credential (the "Test" button). Refreshes the identity
 * and scopes, since a token's grants can be edited at the provider after it
 * was connected.
 */
class VerifyGitAccount
{
    public function __construct(private GitProviderManager $manager) {}

    public function execute(GitAccount $account): GitAccount
    {
        $identity = $this->manager->driver($account->provider)->verify($account);

        $account->update([
            'identifier' => $identity['identifier'],
            'scopes' => $identity['scopes'],
            'last_verified_at' => now(),
        ]);

        return $account->refresh();
    }
}
