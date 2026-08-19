<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\BackupRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one backup off the queue.
 *
 * Not retried. A backup is expensive — a full dump, a multi-gigabyte archive,
 * an upload — and an automatic second attempt against a box that just ran out
 * of disk makes the problem worse rather than better. The schedule brings the
 * next attempt round anyway, and the failed row says why.
 *
 * Unique per target, so a manual run started while the scheduled one is still
 * going cannot produce two archives of the same site at once.
 *
 * Dispatched to the default queue, deliberately: the installer runs a single
 * `queue:work` with no `--queue`, so that is the only queue anything drains.
 * This job used to go to a `backups` queue that no worker consumed, which is
 * why scheduled backups never ran on any real install.
 */
class RunBackup implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    /**
     * An hour. A large site legitimately takes a long time, and killing a
     * backup at the default 60 seconds would mean the feature only ever works
     * for small sites. `retry_after` on the connection must exceed this or a
     * slow backup gets picked up a second time while the first is still
     * running (Laravel queues: job expirations & timeouts).
     */
    public int $timeout = 3600;

    public function __construct(
        public int $backupTargetId,
        public ?int $actorId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'backup-target-'.$this->backupTargetId;
    }

    public function handle(BackupRunner $runner, ActivityLogger $activity): void
    {
        $target = BackupTarget::with(['application', 'storageDestination'])->find($this->backupTargetId);

        if ($target === null) {
            // Dispatched by id so a target deleted between queueing and
            // running is a graceful no-op rather than a crash.
            return;
        }

        $backup = $runner->run($target, $this->actorId);

        $activity->log(
            $backup->status === BackupStatus::Verified ? 'backup.completed' : 'backup.failed',
            $backup,
            [
                'application' => $target->application->name,
                'reason' => $backup->reason ?? '',
            ],
        );
    }

    /**
     * The job died outright — timeout, OOM, worker killed. Without this the
     * row sits at `running` forever and the screen spins on something that
     * stopped.
     */
    public function failed(?Throwable $e): void
    {
        Log::channel('server-ops')->error('backup job crashed', [
            'feature' => 'backup',
            'backup_target' => $this->backupTargetId,
            'detail' => $e?->getMessage(),
        ]);

        Backup::query()
            ->where('backup_target_id', $this->backupTargetId)
            ->whereIn('status', [BackupStatus::Pending->value, BackupStatus::Running->value, BackupStatus::Verifying->value])
            ->update([
                'status' => BackupStatus::Failed->value,
                'reason' => 'crashed',
                'finished_at' => now(),
            ]);

        // A crash is still an attempt. BackupRunner's catch block records one
        // for every handled failure, five lines from here, and says why: a
        // target that stays due retries on the very next tick, turning one
        // broken backup into a new failed row every minute for as long as
        // whatever killed the job keeps killing it. This path — the job dying
        // outright — was the half that never recorded it.
        BackupTarget::query()
            ->where('id', $this->backupTargetId)
            ->update(['last_run_at' => now()]);
    }
}
