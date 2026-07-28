<?php

namespace App\Actions\Server\Git;

use App\Models\GitAccount;
use App\Services\ActivityLogger;

class DisconnectGitAccount
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(GitAccount $account): void
    {
        $this->activityLogger->log('git_account.disconnected', $account, [
            'provider' => $account->provider,
            'label' => $account->label,
        ]);

        $account->delete();
    }
}
