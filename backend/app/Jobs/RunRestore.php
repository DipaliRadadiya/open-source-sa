<?php

namespace App\Jobs;

use App\Enums\RestoreStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\Restore;
use App\Services\ActivityLogger;
use App\Services\Server\Restores\RestoreRunner;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one restore off the queue.
 *
 * **Never retried.** A failed restore has already changed the application, so an automatic second
 * attempt would start from that half-changed state; the next attempt is a person's decision.
 *
 * Unique per application, held to completion rather than to pickup (ShouldBeUniqueUntilProcessing
 * releases at pickup): two restores writing the same site directory at once is worse than none.
 */
class RunRestore implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    /**
     * The same ceiling as {@see RunBackup}: a restore is slower than the backup it came from, so
     * an archive too large to back up in time would also fail to come back.
     *
     * `retry_after` on the connection must exceed this; `config/queue.php` derives it from the
     * same key.
     */
    public int $timeout;

    /**
     * @param  int  $applicationId  the site; 0 for a database restore, which passes `$databaseId`
     */
    public function __construct(public int $restoreId, public int $applicationId, public ?int $databaseId = null)
    {
        $this->timeout = (int) config('server.backups.job_timeout', 21600);
    }

    public function uniqueId(): string
    {
        return $this->databaseId !== null
            ? 'restore-database-'.$this->databaseId
            : 'restore-application-'.$this->applicationId;
    }

    public function handle(RestoreRunner $runner, ActivityLogger $activity): void
    {
        $restore = Restore::with(['backup.target.storageDestination', 'application.systemUser', 'database.backupTarget', 'user'])
            ->find($this->restoreId);

        if ($restore === null) {
            return;
        }

        if ($restore->backup === null) {
            // The source was pruned between queueing and running; fail rather than restore
            // something else.
            $restore->update([
                'status' => RestoreStatus::Failed,
                'reason' => 'missing_backup',
                'finished_at' => now(),
            ]);

            return;
        }

        $restore = $runner->run($restore);

        $succeeded = $restore->status === RestoreStatus::Succeeded;

        // Undo is a restore of a safety backup, logged under its own action. The queue has no
        // signed-in user, so the actor is passed explicitly.
        $activity->log(
            $restore->backup->is_safety
                ? ($succeeded ? 'backup.undone' : 'backup.undo_failed')
                : ($restore->safety_copy === false
                    ? ($succeeded ? 'backup.restored_without_copy' : 'backup.restore_without_copy_failed')
                    : ($succeeded ? 'backup.restored' : 'backup.restore_failed')),
            $restore,
            [
                'application' => $restore->application?->name ?? $restore->database?->name ?? '',
                'reason' => $restore->reason ?? '',
            ],
            $restore->user,
        );
    }

    /**
     * The job died outright (timeout, OOM, worker killed). Without this the row stays `running`
     * forever while the site may be mid-swap.
     */
    public function failed(?Throwable $e): void
    {
        Log::channel('server-ops')->error('restore job crashed', [
            'feature' => 'backup',
            'op' => 'restore',
            'restore' => $this->restoreId,
            'application' => $this->applicationId,
            'database' => $this->databaseId,
            'detail' => $e?->getMessage(),
        ]);

        Restore::query()
            ->whereKey($this->restoreId)
            ->whereIn('status', [RestoreStatus::Pending->value, RestoreStatus::Running->value])
            ->update([
                'status' => RestoreStatus::Failed->value,
                'reason' => 'crashed',
                'finished_at' => now(),
            ]);
    }
}
