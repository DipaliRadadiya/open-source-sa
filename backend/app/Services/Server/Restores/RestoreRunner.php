<?php

namespace App\Services\Server\Restores;

use App\Contracts\RestoreStep;
use App\Enums\RestoreStatus;
use App\Models\Restore;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one restore, step by step, and records how far it got.
 *
 * The same shape as BackupRunner with one difference that matters: the step is
 * written to the row *before* it runs, because a restore that dies outright
 * takes live data with it, and "which stage was it in when the box rebooted?"
 * is the first question anyone will ask.
 */
class RestoreRunner
{
    /** @var list<RestoreStep> */
    private array $steps;

    public function __construct(private ServerOps $serverOps)
    {
        $this->steps = array_map(
            fn (string $class): RestoreStep => app($class),
            (array) config('server.backups.restore_steps', []),
        );
    }

    public function run(Restore $restore): Restore
    {
        $restore->update([
            'status' => RestoreStatus::Running,
            'started_at' => now(),
        ]);

        $context = new RestoreContext(
            $restore,
            $restore->backup,
            $restore->application,
            $this->workingDirectory($restore->id),
        );

        $ran = [];
        $failed = false;

        try {
            foreach ($this->steps as $step) {
                if (! $step->appliesTo($context)) {
                    continue;
                }

                $restore->update(['current_step' => $step->key()]);
                $ran[] = $step;
                $step->run($context);
            }

            $restore->update([
                'status' => RestoreStatus::Succeeded,
                'current_step' => null,
                'reason' => null,
                'safety_backup_id' => $restore->safety_backup_id,
                'rollback_path' => $context->rollbackPath,
                'finished_at' => now(),
            ]);

            return $restore->refresh();
        } catch (Throwable $e) {
            $failed = true;

            Log::channel('server-ops')->error('restore failed', [
                'feature' => 'backup',
                'op' => 'restore',
                'restore' => $restore->id,
                'application' => $restore->application_id,
                'step' => $restore->current_step,
                'reference' => $restore->reference,
                'detail' => $e->getMessage(),
            ]);

            $restore->update([
                'status' => RestoreStatus::Failed,
                // The step that failed, as a stable key. Raw stderr stays in
                // the ops log — it names paths and sometimes credentials.
                'reason' => $restore->current_step ?? 'unknown',
                'rollback_path' => $context->rollbackPath,
                'finished_at' => now(),
            ]);

            return $restore->refresh();
        } finally {
            $this->cleanup($ran, $context, $failed);
        }
    }

    /**
     * @param  list<RestoreStep>  $ran
     */
    private function cleanup(array $ran, RestoreContext $context, bool $failed): void
    {
        foreach (array_reverse($ran) as $step) {
            try {
                $step->cleanup($context, $failed);
            } catch (Throwable $e) {
                Log::channel('server-ops')->warning('restore cleanup failed', [
                    'feature' => 'backup',
                    'op' => 'restore',
                    'step' => $step->key(),
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        foreach ($context->localArtifacts as $path) {
            @unlink($path);
        }

        File::deleteDirectory($context->workingDirectory);

        // The staging directory sits next to the live site, not under the
        // working directory, so it needs removing explicitly — a half-unpacked
        // copy of a site left beside it is both confusing and expensive.
        //
        // Through ServerOps, not File::: it lives in a tree owned by the
        // site's Linux user, where this worker cannot delete anything.
        // File::deleteDirectory() there returns false rather than throwing, so
        // the failure was silent and left behind exactly the copy this is
        // meant to remove.
        if ($context->stagingDirectory !== null) {
            $this->serverOps->run(
                ['rm', '-rf', $context->stagingDirectory],
                ['feature' => 'backup', 'op' => 'restore_staging_cleanup'],
            );
        }
    }

    private function workingDirectory(int $restoreId): string
    {
        $path = rtrim((string) config('server.backups.working_dir', storage_path('app/backups')), '/')
            .'/restore-'.$restoreId;

        File::ensureDirectoryExists($path, 0750);

        return $path;
    }
}
