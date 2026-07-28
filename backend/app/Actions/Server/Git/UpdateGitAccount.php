<?php

namespace App\Actions\Server\Git;

use App\Models\GitAccount;
use App\Services\ActivityLogger;
use App\Services\Git\GitProviderManager;

/**
 * Rename a connection and/or rotate its credential. A changed credential is
 * re-verified before it replaces the working one, so a bad rotation leaves
 * the account exactly as it was.
 */
class UpdateGitAccount
{
    public function __construct(
        private GitProviderManager $manager,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(GitAccount $account, array $data): GitAccount
    {
        $credentialChanged = array_key_exists('token', $data)
            || array_key_exists('host', $data)
            || array_key_exists('workspace', $data);

        if ($credentialChanged) {
            // Verify against a copy so a rejected rotation never persists.
            $candidate = $account->replicate()->fill($data);
            $identity = $this->manager->driver($account->provider)->verify($candidate);

            $data['identifier'] = $identity['identifier'];
            $data['scopes'] = $identity['scopes'];
            $data['last_verified_at'] = now();
        }

        $account->update($data);

        $this->activityLogger->log('git_account.updated', $account, [
            'provider' => $account->provider,
            'label' => $account->label,
        ]);

        return $account->refresh();
    }
}
