<?php

namespace App\Services\Server\Backups;

use App\Contracts\BackupStep;
use App\Enums\BackupStatus;
use App\Exceptions\UploadStalled;
use App\Models\Backup;
use App\Models\BackupTarget;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one backup, step by step, and records how far it got.
 *
 * The row is updated as it goes rather than only at the end, so a run that
 * dies outright — worker killed, box rebooted — leaves evidence of the stage
 * it reached instead of a row that says `running` forever with no clue why.
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
     *                          Stamped at creation, never afterwards — see below.
     */
    public function run(BackupTarget $target, ?int $actorId = null, bool $isSafety = false): Backup
    {
        $logKey = (string) Str::uuid();

        $backup = Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $target->application_id,
            'user_id' => $actorId,
            'type' => $target->type->value,

            // **Born flagged, not flagged afterwards.**
            //
            // `SafetyBackup` used to call this method and then set
            // `is_safety = true` on the row it got back. Everything in between
            // — a multi-gigabyte archive and an upload that can run for an hour
            // — was a window in which the flag was false, and a worker that
            // died anywhere in it left a safety backup recorded as an ordinary
            // one.
            //
            // That is not cosmetic. `PruneOldBackups` protects these rows with
            // `where('is_safety', false)`, precisely so retention cannot
            // "remove the parachute at exactly the wrong moment" — and a
            // mis-flagged row loses that protection, so a later retention pass
            // deletes both the row and the archive in the bucket.
            //
            // It has already happened here: on 2026-09-22 an OOM killed the
            // worker mid-run and left restore 5's safety backup reading
            // `is_safety = 0`, with the restore's `safety_backup_id` still
            // null. The same argument the model makes for `uid` and
            // `storage_destination_id` applies — a value that exists because of
            // *where it is assigned* rather than because someone remembered.
            'is_safety' => $isSafety,

            'status' => BackupStatus::Running,
            'reference' => (string) Str::uuid(),
            'log_key' => $logKey,
            'started_at' => now(),
        ]);

        $workingDirectory = $this->workingDirectory($backup->id);
        $context = new BackupContext($backup, $target, $workingDirectory);
        $ran = [];

        try {
            foreach ($this->steps as $step) {
                if (! $step->appliesTo($context)) {
                    continue;
                }

                // Recorded before running, not after: if the step never
                // returns, this is the only record of where it stopped.
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
                // Cleared on success — it only ever held progress, and leaving
                // the last step name in a `reason` column reads as a failure.
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
                'application' => $target->application_id,
                'step' => $backup->reason,
                'reference' => $backup->reference,
                'log_key' => $logKey,
                'detail' => $e->getMessage(),
            ]);

            $backup->update([
                'status' => BackupStatus::Failed,
                'manifest' => $context->manifest,
                // A stall replaces the step name, which would otherwise say
                // only `upload_artifact` — true, and the least useful half of
                // the truth. The step is implied by the reason; the reason is
                // not implied by the step, and it is the part that tells an
                // operator whether to go and fix something or to wait for the
                // next run.
                'reason' => $e instanceof UploadStalled ? UploadStalled::REASON : $backup->reason,
                'finished_at' => now(),
            ]);

            // A failed scheduled run still counts as an attempt. Without this
            // the target stays due and retries every single tick, turning one
            // broken backup into a loop that fills the disk.
            $target->update(['last_run_at' => now()]);

            return $backup->refresh();
        } finally {
            $this->cleanup($ran, $context);
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

        // Local artefacts go whatever happened. A dump and an archive left
        // behind after a failure fill the disk the next attempt needs — and
        // the next attempt is usually minutes away.
        foreach ($context->localArtifacts as $path) {
            @unlink($path);
        }

        File::deleteDirectory($context->workingDirectory);
    }

    private function workingDirectory(int $backupId): string
    {
        $path = rtrim((string) config('server.backups.working_dir', storage_path('app/backups')), '/')
            .'/run-'.$backupId;

        File::ensureDirectoryExists($path, 0750);

        return $path;
    }
}
