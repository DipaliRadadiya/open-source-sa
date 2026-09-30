<?php

namespace App\Services\Server\Restores;

use App\Contracts\RestoreStep;
use App\Enums\RestoreStatus;
use App\Models\Restore;
use App\Services\Server\Applications\SiteRootLock;
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

    public function __construct(private ServerOps $serverOps, private SiteRootLock $rootLock)
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

            $this->pruneOlderRollbacks($restore, $context);

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
            // An entry of the site root, like the staging directory's creation.
            $this->rootLock->unlocked($context->application, fn () => $this->serverOps->run(
                ['rm', '-rf', $context->stagingDirectory],
                ['feature' => 'backup', 'op' => 'restore_staging_cleanup'],
            ));
        }
    }

    /**
     * Keep only the newest copy of the site a restore moved aside.
     *
     * Every file restore moves the live site to `.rollback-{id}` beside it and
     * nothing ever removed one, so each restore cost a full copy of the site on
     * disk, for good: five restores of a 117 MB site left 585 MB (measured on
     * a real server, 2026-09-24). The newest copy is the one worth having, as
     * "the restore worked but the site is wrong" is asked about the restore
     * just run; the older ones are also in the safety backups.
     *
     * Only paths the panel itself recorded, on earlier *successful* restores of
     * the same site, and only where the path is exactly `.rollback-{that id}`
     * beside the new copy. A failed restore's copy is never touched: if moving
     * it back failed, that copy is the site. `rm -rf` on a symlink removes the
     * link and not its target, so a copy swapped for one cannot redirect this.
     *
     * Never fails the restore: it has already succeeded, and tidying up is not
     * a reason to report otherwise.
     */
    private function pruneOlderRollbacks(Restore $restore, RestoreContext $context): void
    {
        if ($context->rollbackPath === null) {
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
                $result = $this->rootLock->unlocked($context->application, fn () => $this->serverOps->run(
                    ['rm', '-rf', $path],
                    ['feature' => 'backup', 'op' => 'restore_prune_rollback', 'application' => $restore->application_id],
                    timeout: 600,
                ));

                if ($result->failed()) {
                    continue;
                }

                $previous->update(['rollback_path' => null]);
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

    private function workingDirectory(int $restoreId): string
    {
        $path = rtrim((string) config('server.backups.working_dir', storage_path('app/backups')), '/')
            .'/restore-'.$restoreId;

        File::ensureDirectoryExists($path, 0750);

        return $path;
    }
}
