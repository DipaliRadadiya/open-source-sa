<?php

namespace App\Services\Server\Backups;

use App\Enums\BackupStatus;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Support\WorkerProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Closes out backup rows whose job is provably gone.
 *
 * A worker that dies without warning (`kill -9`, OOM, reboot) leaves its row stranded at
 * `running`. Every start path refuses while one is in flight, so a stranded row would block
 * that site's backups for good.
 *
 * Two bounds: a run that has reported bytes is judged on its heartbeat, one that has not on
 * its age. Both err late on purpose: expiring early frees the guard for a second backup to
 * write the same archive key alongside a first that is still running.
 *
 * Reaped rows are marked `Failed`, not deleted or ignored, so the evidence stays and the
 * screen stops showing a run in progress.
 */
class StaleBackupReaper
{
    /**
     * Statuses that hold the in-flight guard closed.
     *
     * @var array<int, string>
     */
    /**
     * Every key BackupRunner writes to `reason` while a step runs.
     *
     * @var array<int, string>
     */
    public const STEPS = [
        'check_local_space',
        'dump_database',
        'archive_files',
        'archive_volumes',
        'upload_artifact',
        'verify_artifact',
        'prune_old_backups',
    ];

    public const IN_FLIGHT = [
        BackupStatus::Pending->value,
        BackupStatus::Running->value,
        BackupStatus::Verifying->value,
    ];

    /**
     * How long a run with *no heartbeat at all* is given before it counts dead.
     *
     * Used only before the upload has reported a byte (a crash during dump or archive).
     *
     * Deliberately not `RunBackup::uniqueFor()`: the job timeout is six hours, which would lock
     * a target out that long. Must stay comfortably above the longest archive step, or the
     * reaper frees the guard while the first backup is still writing.
     */
    public static function staleAfterSeconds(): int
    {
        return (int) config('server.backups.no_heartbeat_stale_seconds', 3900);
    }

    /**
     * Whether this row is old enough that nothing can still be working on it.
     *
     * Measured from `created_at` when `started_at` is empty: a job that never reached a worker
     * sits at `pending` with no start time.
     */
    public function isStale(Backup $backup): bool
    {
        if (! in_array($backup->status->value, self::IN_FLIGHT, true)) {
            return false;
        }

        // Worker is dead: nothing can still be writing. Only a definite "gone" counts;
        // anything uncertain falls through to the time-based rules below.
        if ($backup->worker_pid !== null && WorkerProcess::gone($backup->worker_pid, $backup->worker_started)) {
            return true;
        }

        // A run reporting bytes is alive whatever its age; measure the last sign of life, so a
        // crashed run is collectable within the stall window, not the full timeout. Not
        // `staleAfterSeconds()`: a transfer stalled past the upload guard is already abandoned.
        if ($backup->progress_at !== null) {
            return $backup->progress_at->addSeconds(self::heartbeatGraceSeconds())->isPast();
        }

        $startedAt = $backup->started_at ?? $backup->created_at;

        return $startedAt !== null
            && $startedAt->addSeconds(self::staleAfterSeconds())->isPast();
    }

    /**
     * How long a silent heartbeat is tolerated before the run counts as dead.
     *
     * Must stay above the upload stall guard: cURL abandons a transfer after
     * `upload_stall_seconds`, and this waits slightly longer. Reversed, the reaper would close a
     * run the worker is still inside and a second backup would write the same archive key.
     */
    public static function heartbeatGraceSeconds(): int
    {
        // Via RunBackup: PHP does not allow `Trait::CONSTANT`.
        return (int) config('server.backups.upload_stall_seconds', 1200)
            + RunBackup::UNIQUE_LOCK_GRACE;
    }

    /**
     * Close out every abandoned run for a target. Returns how many were reaped.
     *
     * Called before the in-flight check on every path that starts a backup.
     */
    public function reap(BackupTarget $target): int
    {
        $stale = Backup::query()
            ->where('backup_target_id', $target->id)
            ->whereIn('status', self::IN_FLIGHT)
            ->get()
            ->filter(fn (Backup $backup): bool => $this->isStale($backup));

        foreach ($stale as $backup) {
            $this->close($backup);
        }

        return $stale->count();
    }

    /**
     * Mark one run abandoned.
     *
     * `finished_at` is when we noticed; the real time of death is unknowable.
     */
    public function close(Backup $backup): void
    {
        Log::channel('server-ops')->warning('backup abandoned', [
            'feature' => 'backup',
            'backup' => $backup->id,
            'backup_target' => $backup->backup_target_id,
            'status' => $backup->status->value,
            'started_at' => $backup->started_at?->toIso8601String(),
        ]);

        $backup->update([
            'status' => BackupStatus::Failed,
            'reason' => 'abandoned',
            // `reason` held the running step until now; this is the last chance to keep it.
            'stopped_step' => self::runningStep($backup),
            'stopped_cause' => app(BackupStopCause::class)->for($backup),
            'finished_at' => now(),
        ]);

        self::discardWorkingDirectory($backup);
    }

    /**
     * Remove what a dead run left on this server's disk.
     *
     * The runner's `finally` cleanup never runs when the worker is killed, leaving a half
     * archive in `storage/app/backups/run-N`. No copy may stay on a user's server unasked.
     */
    public static function discardWorkingDirectory(Backup $backup): void
    {
        $path = BackupRunner::workingDirectoryFor($backup->id);

        if (is_dir($path) && ! File::deleteDirectory($path)) {
            Log::channel('server-ops')->warning('backup working folder left behind', [
                'feature' => 'backup',
                'backup' => $backup->id,
                'path' => $path,
            ]);
        }
    }

    /**
     * The step a run was on when it stopped, or null.
     *
     * While a backup runs, `reason` holds the step it is on; any other value is a reason, not
     * a step.
     */
    public static function runningStep(Backup $backup): ?string
    {
        $step = $backup->reason;

        return is_string($step) && in_array($step, self::STEPS, true) ? $step : null;
    }

    /**
     * Is there a run for this target that could genuinely still be working?
     *
     * Reaps first, so the answer is about live jobs rather than about rows.
     */
    public function hasLiveRun(BackupTarget $target): bool
    {
        $this->reap($target);

        return Backup::query()
            ->where('backup_target_id', $target->id)
            ->whereIn('status', self::IN_FLIGHT)
            ->exists();
    }
}
