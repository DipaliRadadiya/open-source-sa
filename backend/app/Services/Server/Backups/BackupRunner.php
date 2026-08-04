<?php

namespace App\Services\Server\Backups;

use App\Contracts\BackupStep;
use App\Enums\BackupStatus;
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

    public function run(BackupTarget $target, ?int $actorId = null): Backup
    {
        $backup = Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $target->application_id,
            'user_id' => $actorId,
            'type' => $target->type->value,
            'status' => BackupStatus::Running,
            'reference' => (string) Str::uuid(),
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
                'detail' => $e->getMessage(),
            ]);

            $backup->update([
                'status' => BackupStatus::Failed,
                'manifest' => $context->manifest,
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
