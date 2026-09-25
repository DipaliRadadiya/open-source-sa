<?php

namespace App\Actions\Server\Git;

use App\Models\GitAccount;
use App\Services\ActivityLogger;
use App\Support\NameList;
use Illuminate\Validation\ValidationException;

/**
 * Disconnect a git account, refusing while any application deploys with it.
 *
 * The foreign key is `nullOnDelete`, so the database lets this through and
 * quietly strands every site using the account: no credential and no URL, so
 * the next deploy — a push, too — fails on `git remote add origin ""`. Refused
 * instead, naming the sites, so the user relinks them first.
 */
class DisconnectGitAccount
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(GitAccount $account): void
    {
        $names = $account->applications()->orderBy('name')->pluck('name');

        if ($names->isNotEmpty()) {
            throw ValidationException::withMessages([
                'git_account' => [__('errors/git.in_use', [
                    'name' => $account->label,
                    'applications' => NameList::summarise($names->all(), 'errors/git.and_more'),
                ])],
            ]);
        }

        $this->activityLogger->log('git_account.disconnected', $account, [
            'provider' => $account->provider,
            'label' => $account->label,
        ]);

        $account->delete();
    }
}
