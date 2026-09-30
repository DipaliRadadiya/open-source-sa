<?php

namespace App\Console\Commands;

use App\Models\SystemUser;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;
use Illuminate\Console\Command;

/**
 * The one-step rollback for closed homes: drop the ACL and give every system
 * user's home back its old `o+x`. `sites:resync` closes them again, so run it
 * only together with reverting the change that closes them.
 */
class OpenHomeDirectories extends Command
{
    protected $signature = 'homes:open';

    protected $description = 'Reopen every system user home to other local accounts (rollback of the home ACL)';

    public function handle(HomeDirectoryAccess $homeAccess): int
    {
        $opened = 0;

        foreach (SystemUser::query()->orderBy('id')->get() as $user) {
            if ($homeAccess->open($user)) {
                $opened++;
            }
        }

        $this->info("Home directories reopened: {$opened}.");

        return self::SUCCESS;
    }
}
