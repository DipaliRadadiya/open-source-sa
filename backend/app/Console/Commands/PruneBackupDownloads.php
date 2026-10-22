<?php

namespace App\Console\Commands;

use App\Services\Server\Backups\Downloads\PreparedDownloads;
use Illuminate\Console\Command;

/**
 * Removes prepared backup downloads (SFTP/FTP) once their hour is up.
 *
 * Each copy is a whole site plus its database on the panel's own disk, kept
 * only long enough to be downloaded. Also clears failed rows past the hour,
 * preparations whose worker died, and any copy whose backup was removed by a
 * cascade rather than a model delete.
 *
 * No activity log entry: nobody did anything, a timer ran out.
 */
class PruneBackupDownloads extends Command
{
    protected $signature = 'backups:prune-downloads';

    protected $description = 'Remove prepared backup downloads that have expired.';

    public function handle(PreparedDownloads $downloads): int
    {
        $removed = $downloads->prune();

        $this->info("Removed {$removed} prepared backup downloads.");

        return self::SUCCESS;
    }
}
