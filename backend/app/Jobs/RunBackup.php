<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\StaleBackupReaper;
use App\Services\Server\Backups\Storage\GoogleHttpClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
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
 * **Unique *until processing*, not until completion.** A lock held for the
 * whole run is only released if the job reaches an end — and the case that
 * matters is the one where it does not: `kill -9`, an OOM, a reboot. The lock
 * then survives with the job's full `uniqueFor` TTL, and every dispatch for
 * that target is silently discarded. Not refused — discarded, with the API
 * still answering `202`, so the panel reports a backup started and nothing ran.
 * That happened on 2026-09-21 and cost an afternoon; raising the timeout to six
 * hours would have stretched the same dead window from 65 minutes to six hours.
 *
 * Releasing at pickup means a killed worker leaves nothing behind. What then
 * prevents two concurrent runs is the backup row itself — `hasLiveRun()`, which
 * every dispatch path now checks, including the scheduler — and that guard is
 * strictly better than the lock was: it is visible in the database, it says
 * *why* on screen, and since it reads the progress heartbeat it clears within
 * the stall window instead of the job's whole timeout.
 *
 * Dispatched to the default queue, deliberately: the installer runs a single
 * `queue:work` with no `--queue`, so that is the only queue anything drains.
 * This job used to go to a `backups` queue that no worker consumed, which is
 * why scheduled backups never ran on any real install.
 */
class RunBackup implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    /**
     * Configuration, not a literal, and not an hour.
     *
     * A hardcoded 3600 did not make large backups slow, it made them
     * impossible: a 24 GB archive measured 13 minutes to build and — before
     * the link's congestion control was fixed — over three hours to upload, so
     * the worker killed the job at the hour mark on every single attempt. The
     * run never reached `verified`, and because nothing recorded progress, the
     * failure was indistinguishable from a hang.
     *
     * The ceiling is high enough to be irrelevant to honest work. What ends a
     * genuinely wedged run is the byte-rate stall guard
     * ({@see GoogleHttpClient}), not a
     * clock — because a clock cannot tell a slow upload from a dead one, and a
     * limit tuned to catch the dead one kills the slow one too.
     *
     * `retry_after` on the connection must exceed this or a slow backup is
     * picked up a second time while the first is still running (Laravel queues:
     * job expirations & timeouts). `config/queue.php` derives it from the same
     * key so the two cannot drift apart.
     */
    public int $timeout;

    public function __construct(
        public int $backupTargetId,
        public ?int $actorId = null,
    ) {
        $this->timeout = (int) config('server.backups.job_timeout', 21600);
    }

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

        // The lock is released at pickup now, so two jobs for one target can
        // legitimately sit in the queue if the second was dispatched while the
        // first was running. Both callers check this before dispatching; this
        // is the check that cannot be raced, because it happens on the worker
        // that is about to start writing. Two concurrent runs would archive one
        // site twice, to one key, on one disk.
        if (app(StaleBackupReaper::class)->hasLiveRun($target)) {
            Log::channel('server-ops')->info('backup skipped, one is already running', [
                'feature' => 'backup',
                'backup_target' => $this->backupTargetId,
            ]);

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
