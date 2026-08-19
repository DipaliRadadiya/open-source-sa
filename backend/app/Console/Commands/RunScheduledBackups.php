<?php

namespace App\Console\Commands;

use App\Jobs\RunBackup;
use App\Models\BackupTarget;
use App\Services\Server\Backups\StaleBackupReaper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Wakes every minute and queues whichever targets are due.
 *
 * The schedule lives in the database rather than in a cron file, so it can
 * never drift with the user-managed Cronjobs feature — the same model the
 * disk cleaner uses. Due-ness is decided by BackupTarget::isDue(), which
 * compares the last run against the previous cron slot, so a tick missed to a
 * reboot still fires once when the box comes back instead of skipping the day.
 */
class RunScheduledBackups extends Command
{
    protected $signature = 'backups:run-due';

    protected $description = 'Queue any backup targets whose schedule is due';

    public function handle(StaleBackupReaper $reaper): int
    {
        $now = Date::now();

        $due = BackupTarget::query()
            ->where('enabled', true)
            ->where('frequency', '!=', 'manual')
            ->with(['application', 'storageDestination'])
            ->get()
            ->filter(fn (BackupTarget $target): bool => $target->isDue($now));

        foreach ($due as $target) {
            // Close out anything stranded before queueing. A row left at
            // `running` by a killed worker blocks every later run for that
            // target, and this tick is the only thing that visits a target
            // nobody is looking at — without it a site stops being backed up
            // and the first anyone hears of it is when they need the backup.
            $reaper->reap($target);

            // Not marked as run here: the runner sets last_run_at when it
            // finishes, success or failure. Marking it now would mean a job
            // that never reached a worker looked like a completed backup.
            RunBackup::dispatch($target->id);
        }

        if ($due->isNotEmpty()) {
            $this->info("queued {$due->count()} backup(s)");
        }

        return self::SUCCESS;
    }
}
