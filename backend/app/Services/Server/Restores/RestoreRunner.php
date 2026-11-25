<?php

namespace App\Services\Server\Restores;

use App\Contracts\RestoreStep;
use App\Enums\RestoreStatus;
use App\Exceptions\NotEnoughBackupSpace;
use App\Models\Restore;
use App\Services\Server\Applications\SiteRootLock;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one restore, step by step, and records how far it got.
 *
 * Like BackupRunner, but the step is written to the row before it runs: a restore that dies
 * outright takes live data with it, so the stage it died in must be known.
 */
class RestoreRunner
{
    /** @var list<RestoreStep> */
    private array $steps;

    public function __construct(private ServerOps $serverOps, private SiteRootLock $rootLock, private RollbackCopies $rollbacks)
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
            $restore->database,
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

            $this->pruneOlderRollbacks($restore, $context);
            $this->removeOwnRollback($restore, $context);

            return $restore->refresh();
        } catch (Throwable $e) {
            $failed = true;

            Log::channel('server-ops')->error('restore failed', [
                'feature' => 'backup',
                'op' => 'restore',
                'restore' => $restore->id,
                ...$context->logSubject(),
                'step' => $restore->current_step,
                'reference' => $restore->reference,
                'detail' => $e->getMessage(),
            ]);

            $restore->update([
                'status' => RestoreStatus::Failed,
                // The failed step as a stable key; raw stderr stays in the ops log (it holds paths,
                // credentials).
                // No space for the safety copy is its own reason: the user can free some.
                'reason' => $e instanceof NotEnoughBackupSpace ? NotEnoughBackupSpace::REASON : ($restore->current_step ?? 'unknown'),
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

        // The staging directory sits next to the live site, not under the working directory, so
        // remove it explicitly. Through ServerOps, not File::, which returns false silently in a
        // tree owned by the site's Linux user.
        if ($context->stagingDirectory !== null && $context->application === null) {
            // A database restore unpacks in the panel's working directory (no site root to
            // unlock), but tar ran as root there, so the delete above could not finish it.
            $this->serverOps->run(
                ['rm', '-rf', $context->stagingDirectory],
                ['feature' => 'backup', 'op' => 'restore_staging_cleanup'],
            );
        } elseif ($context->stagingDirectory !== null) {
            // An entry of the site root, like the staging directory's creation.
            $this->rootLock->unlocked($context->application, fn () => $this->serverOps->run(
                ['rm', '-rf', $context->stagingDirectory],
                ['feature' => 'backup', 'op' => 'restore_staging_cleanup'],
            ));
        }
    }

    /**
     * Remove copies of the site that earlier restores moved aside to `.rollback-{id}`.
     *
     * A successful restore removes its own copy ({@see removeOwnRollback()}); this clears the ones
     * left by earlier restores or failed removals. Only paths the panel recorded on earlier
     * successful restores of the same site, exactly `.rollback-{that id}` beside the new copy.
     * A failed restore's copy is never touched: it may be the site
     * ({@see RollbackCopies::pruneFailed()}). Never fails the restore.
     */
    private function pruneOlderRollbacks(Restore $restore, RestoreContext $context): void
    {
        if ($context->rollbackPath === null || $context->application === null) {
            return;
        }

        $parent = dirname($context->rollbackPath);

        $older = Restore::query()
            ->where('application_id', $restore->application_id)
            ->where('id', '!=', $restore->id)
            ->where('status', RestoreStatus::Succeeded)
            ->whereNotNull('rollback_path')
            ->get();

        foreach ($older as $previous) {
            $path = (string) $previous->rollback_path;

            if ($path !== $parent.'/.rollback-'.$previous->id) {
                continue;
            }

            try {
                $this->rollbacks->remove($previous, $context->application);
            } catch (Throwable $e) {
                Log::channel('server-ops')->warning('old restore copy not removed', [
                    'feature' => 'backup',
                    'op' => 'restore_prune_rollback',
                    'restore' => $previous->id,
                    'detail' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * This restore's own copy of the old site, removed once the restore has worked.
     *
     * The copy only exists to put the site back if the restore fails part-way. Going back later is
     * the safety backup's job (Undo). Kept, with its path recorded, when removal fails so the next
     * restore removes it ({@see pruneOlderRollbacks()}). Never fails the restore.
     */
    private function removeOwnRollback(Restore $restore, RestoreContext $context): void
    {
        if ($context->rollbackPath === null || $context->application === null) {
            return;
        }

        try {
            $removed = $this->rollbacks->remove($restore->refresh(), $context->application);
        } catch (Throwable $e) {
            $removed = false;
        }

        if (! $removed) {
            Log::channel('server-ops')->warning('restore copy not removed', [
                'feature' => 'backup',
                'op' => 'restore_remove_rollback',
                'restore' => $restore->id,
            ]);
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
