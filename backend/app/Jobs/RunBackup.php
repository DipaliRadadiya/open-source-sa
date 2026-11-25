<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\DatabaseImport;
use App\Models\Restore;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\StagingManager;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\BackupStopCause;
use App\Services\Server\Backups\StaleBackupReaper;
use App\Services\Server\Backups\Storage\GoogleHttpClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one backup off the queue.
 *
 * Not retried: a backup is expensive, and a second attempt against a box that just ran out of disk
 * makes it worse. The schedule brings the next attempt and the failed row says why.
 *
 * Unique per target, so a manual run cannot overlap the scheduled one. Unique *until processing*,
 * not until completion: a lock held for the whole run survives `kill -9`, an OOM or a reboot for
 * the full `uniqueFor` TTL, and every dispatch for that target is then silently discarded while the
 * API still answers `202`. Once the lock is released at pickup, concurrent runs are prevented by
 * the backup row itself (`hasLiveRun()`, checked by every dispatch path including the scheduler),
 * which clears within the stall window instead of the job's whole timeout.
 *
 * Dispatched to the default queue, deliberately: the installer runs a single `queue:work` with no
 * `--queue`, so a dedicated `backups` queue would never be drained.
 */
class RunBackup implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    /**
     * Configuration, not an hour: a hardcoded 3600 made large backups impossible (a 24 GB archive
     * is killed at the hour mark on every attempt and never reaches `verified`).
     *
     * The ceiling is high enough to be irrelevant to honest work. A wedged run is ended by the
     * byte-rate stall guard ({@see GoogleHttpClient}), because a clock cannot tell a slow upload
     * from a dead one.
     *
     * `retry_after` on the connection must exceed this, or a slow backup is picked up a second time
     * while the first is still running. `config/queue.php` derives it from the same key.
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
        $target = BackupTarget::with(['application', 'database', 'storageDestination'])->find($this->backupTargetId);

        if ($target === null) {
            // Dispatched by id: a target deleted before the job runs is a no-op, not a crash.
            return;
        }

        // The lock is released at pickup, so two jobs for one target can sit in the queue. The
        // dispatchers check too; this check on the worker cannot be raced. A scheduled run that
        // falls during a restore is skipped, not queued behind it. See Restore::inProgressFor().
        $busy = $target->isDatabaseTarget()
            // A database on its own: its restore, or an import replacing it.
            ? Restore::inProgressForDatabase($target->database_id) || DatabaseImport::query()->where('database_id', $target->database_id)->open()->exists()
            : Restore::inProgressFor($target->application_id) || StagingManager::pushInProgress($target->application_id);

        if ($busy) {
            Log::channel('server-ops')->info('backup skipped, a restore or staging push of this application is in progress', [
                'feature' => 'backup',
                'backup_target' => $this->backupTargetId,
            ]);

            // A manual run leaves a failed row saying why it was skipped; a scheduled run stays quiet.
            if ($this->actorId !== null) {
                $skipped = Backup::create([
                    'backup_target_id' => $target->id,
                    'application_id' => $target->application_id,
                    'database_id' => $target->database_id,
                    'subject_name' => $target->isDatabaseTarget() ? $target->database?->name : null,
                    'subject_engine' => $target->isDatabaseTarget() ? $target->database?->engine : null,
                    'user_id' => $this->actorId,
                    'type' => $target->type->value,
                    'is_safety' => false,
                    'status' => BackupStatus::Failed,
                    'reason' => 'restore_in_progress',
                    'reference' => (string) Str::uuid(),
                    'started_at' => now(),
                    'finished_at' => now(),
                ]);

                $activity->log('backup.failed', $skipped, [
                    'application' => $target->subjectName(),
                    'reason' => __('backup.errors.restore_in_progress'),
                ], User::find($this->actorId));
            }

            return;
        }

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
                'application' => $target->subjectName(),
                'reason' => $backup->reason ?? '',
            ],
            // Null for a scheduled run, which the log shows as System.
            $this->actorId !== null ? User::find($this->actorId) : null,
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

        // One row at a time, not one UPDATE: each keeps its own step and cause.
        Backup::query()
            ->where('backup_target_id', $this->backupTargetId)
            ->whereIn('status', [BackupStatus::Pending->value, BackupStatus::Running->value, BackupStatus::Verifying->value])
            ->get()
            ->each(function (Backup $backup) use ($e): void {
                $backup->update([
                    'status' => BackupStatus::Failed,
                    'reason' => 'crashed',
                    'stopped_step' => StaleBackupReaper::runningStep($backup),
                    'stopped_cause' => app(BackupStopCause::class)->for($backup, $e),
                    'finished_at' => now(),
                ]);

                // The runner's own clean-up never ran.
                StaleBackupReaper::discardWorkingDirectory($backup);
            });

        // A crash is still an attempt, as in BackupRunner's catch block: otherwise a target that
        // stays due retries on every tick and writes a new failed row each minute.
        BackupTarget::query()
            ->where('id', $this->backupTargetId)
            ->update(['last_run_at' => now()]);
    }
}
