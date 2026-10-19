<?php

namespace App\Console\Commands;

use App\Services\Server\Restores\RollbackCopies;
use Illuminate\Console\Command;

/**
 * Removes the `.rollback-{id}` copy a failed restore left beside a site, a week
 * after it failed (2026-10-18).
 *
 * Nothing removed these: the runner tidies only after a restore that worked.
 * Each is a whole copy of the site. The rules that keep one that is still
 * needed are in {@see RollbackCopies::pruneFailed()}.
 */
class PruneRestoreRollbacks extends Command
{
    protected $signature = 'restores:prune-rollbacks';

    protected $description = "Remove failed restores' site copies older than a week, where that is safe";

    public function handle(RollbackCopies $rollbacks): int
    {
        $removed = $rollbacks->pruneFailed();

        $this->info("Removed {$removed} copy(ies) left by failed restores.");

        return self::SUCCESS;
    }
}
