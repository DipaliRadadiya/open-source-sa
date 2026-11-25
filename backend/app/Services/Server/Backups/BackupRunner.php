<?php

namespace App\Services\Server\Backups;

use App\Contracts\BackupStep;
use App\Enums\BackupStatus;
use App\Exceptions\NotEnoughBackupSpace;
use App\Exceptions\NoVolumesToBackUp;
use App\Exceptions\UploadStalled;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use App\Support\WorkerProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one backup, step by step, and records how far it got.
 *
 * The row is updated as it goes, so a run that dies outright leaves the stage it reached.
 */
class BackupRunner
{
    /** @var list<BackupStep> */
    private array $steps;

    public function __construct()
    {
        $this->steps = array_map(
            fn (string $class): BackupStep => app($class),
            (array) config('server.backups.steps', []),
        );
    }

    /**
     * @param  bool  $isSafety  Whether this run is a restore's way back.
     *                          Stamped at creation, never afterwards.
     */
    public function run(BackupTarget $target, ?int $actorId = null, bool $isSafety = false): Backup
    {
        $logKey = (string) Str::uuid();

        $backup = Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $target->application_id,
            // Records the source, so the backup stays restorable after the database is archived.
            'database_id' => $target->database_id,
            'subject_name' => $target->isDatabaseTarget() ? $target->database?->name : null,
            'subject_engine' => $target->isDatabaseTarget() ? $target->database?->engine : null,
            'user_id' => $actorId,
            'type' => $target->type->value,

            // Born flagged, never flagged afterwards: a worker that died in between would leave a
            // safety backup marked ordinary, and `PruneOldBackups` (which skips safety rows)
            // would later delete it and its archive.
            'is_safety' => $isSafety,

            'status' => BackupStatus::Running,
            'reference' => (string) Str::uuid(),
            'log_key' => $logKey,
            'started_at' => now(),
            // Lets StaleBackupReaper clear a run as soon as its worker is dead.
            'worker_pid' => WorkerProcess::pid(),
            'worker_started' => WorkerProcess::startTime(),
        ]);

        $workingDirectory = $this->workingDirectory($backup->id);
        $context = new BackupContext($backup, $target, $workingDirectory);
        $ran = [];

        try {
            foreach ($this->steps as $step) {
                if (! $step->appliesTo($context)) {
                    continue;
                }

                // Recorded before running: if the step never returns, this shows where it stopped.
                $backup->update(['reason' => $step->key()]);

                $ran[] = $step;
                $step->run($context);

                if ($step->key() === 'upload_artifact') {
                    $backup->update(['status' => BackupStatus::Verifying]);
                }
            }

            $backup->update([
                'status' => BackupStatus::Verified,
                'manifest' => $context->manifest,
                'size_bytes' => $context->sizeBytes,
                // Cleared on success: it only held progress, and would read as a failure.
                'reason' => null,
                'finished_at' => now(),
                'verified_at' => now(),
            ]);

            $target->update(['last_run_at' => now()]);

            return $backup->refresh();
        } catch (Throwable $e) {
            Log::channel('server-ops')->error('backup failed', [
                'feature' => 'backup',
                'backup' => $backup->id,
                ...$context->logSubject(),
                'step' => $backup->reason,
                'reference' => $backup->reference,
                'log_key' => $logKey,
                'detail' => $e->getMessage(),
            ]);

            $backup->update([
                'status' => BackupStatus::Failed,
                'manifest' => $context->manifest,
                // These reasons replace the step name: they tell the user what to do next.
                'reason' => match (true) {
                    $e instanceof UploadStalled => UploadStalled::REASON,
                    $e instanceof NoVolumesToBackUp => NoVolumesToBackUp::REASON,
                    $e instanceof NotEnoughBackupSpace => NotEnoughBackupSpace::REASON,
                    default => $backup->reason,
                },
                'error_class' => $this->storageErrorClass($backup, $e),
                'finished_at' => now(),
            ]);

            // A failed run still counts as an attempt, or the target retries every tick.
            $target->update(['last_run_at' => now()]);

            return $backup->refresh();
        } finally {
            $this->cleanup($ran, $context);
        }
    }

    /**
     * Why the storage refused, when a storage step is what failed
     * (`invalid_credentials`, `bucket_not_found`, `unreachable`…, as the destination test reports).
     *
     * Only for steps that talk to the storage: the classifier defaults to "unreachable".
     */
    private function storageErrorClass(Backup $backup, Throwable $e): ?string
    {
        if (! in_array($backup->reason, ['check_local_space', 'upload_artifact', 'verify_artifact', 'prune_old_backups'], true) || $e instanceof UploadStalled) {
            return null;
        }

        // This server's disk, whatever the destination: not "the bucket is unreachable".
        if ($e instanceof NotEnoughBackupSpace) {
            return 'local_no_space';
        }

        $destination = $backup->destination();

        if ($destination === null) {
            return null;
        }

        try {
            return Str::after(app(StorageDriverFactory::class)->for($destination)->classify($e), 'storage.test.');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<BackupStep>  $ran
     */
    private function cleanup(array $ran, BackupContext $context): void
    {
        foreach (array_reverse($ran) as $step) {
            try {
                $step->cleanup($context);
            } catch (Throwable $e) {
                Log::channel('server-ops')->warning('backup cleanup failed', [
                    'feature' => 'backup',
                    'step' => $step->key(),
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        // Local artefacts go whatever happened, or they fill the disk the next attempt needs.
        foreach ($context->localArtifacts as $path) {
            @unlink($path);
        }

        File::deleteDirectory($context->workingDirectory);
    }

    private function workingDirectory(int $backupId): string
    {
        $path = self::workingDirectoryFor($backupId);

        File::ensureDirectoryExists($path, 0750);

        return $path;
    }

    /** Where a run keeps its dump and archive until they are uploaded. */
    public static function workingDirectoryFor(int $backupId): string
    {
        return rtrim((string) config('server.backups.working_dir', storage_path('app/backups')), '/')
            .'/run-'.$backupId;
    }
}
