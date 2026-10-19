<?php

namespace App\Services\Server\Restores;

use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\Restore;
use App\Services\Server\Applications\SiteRootLock;
use App\Services\Server\Backups\BackupRoot;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `.rollback-{id}` copies a file restore leaves beside the site.
 *
 * `SwapFiles` moves the live site aside before putting the restored one in its
 * place, and the copy stays. After a successful restore the runner keeps only
 * the newest one per site. A *failed* restore's copy was never removed by
 * anything (2026-10-18): it is left when the restore fails after the swap —
 * the restored site came back but its process would not start — or when moving
 * the old site back failed. A whole second copy of the site, kept for good.
 */
class RollbackCopies
{
    /** Days a failed restore's copy is kept before {@see pruneFailed()} removes it. Operator's call, 2026-10-18. */
    public const FAILED_KEEP_DAYS = 7;

    public function __construct(
        private ServerOps $serverOps,
        private SiteRootLock $rootLock,
        private BackupRoot $roots,
    ) {}

    /**
     * Remove failed restores' copies older than {@see FAILED_KEEP_DAYS}, where
     * that is safe. Returns how many were removed.
     *
     * A copy goes only when all of these hold, because when the move back
     * failed the copy *is* the site:
     * - the path is exactly the `.rollback-{id}` beside this site's root, as
     *   the panel itself recorded it;
     * - the site's root is there now (asked, not assumed — a refused or failed
     *   check keeps the copy);
     * - the safety backup this restore took first still exists and was
     *   verified, so what the copy holds is also in a backup the user can see.
     */
    public function pruneFailed(): int
    {
        $removed = 0;

        $restores = Restore::query()
            ->where('status', RestoreStatus::Failed)
            ->whereNotNull('rollback_path')
            ->where('finished_at', '<', now()->subDays(self::FAILED_KEEP_DAYS))
            ->with(['application.systemUser', 'backup'])
            ->get();

        foreach ($restores as $restore) {
            $application = $restore->application;

            if ($application === null || $restore->backup === null || ! $this->safetyBackupKept($restore)) {
                continue;
            }

            try {
                $siteRoot = $this->roots->forBackup($restore->backup, $application);

                if ((string) $restore->rollback_path !== dirname($siteRoot).'/.rollback-'.$restore->id) {
                    continue;
                }

                $present = $this->serverOps->run(
                    ['test', '-d', $siteRoot],
                    ['feature' => 'backup', 'op' => 'restore_prune_failed_check', 'application' => $application->id],
                    timeout: 30,
                    expectedExitCodes: [1],
                );

                if (! $present->ok) {
                    continue;
                }

                if ($this->remove($restore, $application)) {
                    $removed++;
                }
            } catch (Throwable $e) {
                Log::channel('server-ops')->warning('failed restore copy not removed', [
                    'feature' => 'backup',
                    'op' => 'restore_prune_failed',
                    'restore' => $restore->id,
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    /**
     * Delete one recorded copy and clear it from the row. False when `rm`
     * failed; the row then still points at what is left.
     *
     * The site root's immutable flag is lifted around it, as for every other
     * change beside the root. `rm -rf` on a symlink removes the link and not
     * its target, so a copy swapped for one cannot redirect this.
     */
    public function remove(Restore $restore, Application $application): bool
    {
        $path = (string) $restore->rollback_path;

        $result = $this->rootLock->unlocked($application, fn () => $this->serverOps->run(
            ['rm', '-rf', $path],
            ['feature' => 'backup', 'op' => 'restore_prune_rollback', 'application' => $application->id],
            timeout: 600,
        ));

        if ($result->failed()) {
            return false;
        }

        $restore->update(['rollback_path' => null]);

        return true;
    }

    private function safetyBackupKept(Restore $restore): bool
    {
        return $restore->safety_backup_id !== null
            && Backup::query()
                ->whereKey($restore->safety_backup_id)
                ->where('status', BackupStatus::Verified)
                ->exists();
    }
}
