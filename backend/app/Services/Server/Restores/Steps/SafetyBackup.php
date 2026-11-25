<?php

namespace App\Services\Server\Restores\Steps;

use App\Actions\Server\Backup\DeleteBackup;
use App\Contracts\RestoreStep;
use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Exceptions\NotEnoughBackupSpace;
use App\Models\Backup;
use App\Models\Restore;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Restores\RestoreContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Backs up the current state before it is overwritten.
 *
 * Without this a restore is a one-way door: pick the wrong backup and the previous state is gone.
 *
 * On by default; a restore with `safety_copy` off skips it. Then there is no Undo, and a database
 * import that fails part-way leaves the database as far as it got (files are still put back from
 * the copy SwapFiles moved aside). The restore dialog warns before it is turned off.
 *
 * If the safety backup fails, the restore stops, even when the bucket is full: better than
 * silently removing the only way back.
 */
class SafetyBackup implements RestoreStep
{
    /** Safety backups kept per target. Bounded, or they accumulate forever. */
    private const KEEP = 2;

    public function __construct(
        private BackupRunner $backups,
        private DeleteBackup $deleteBackup,
    ) {}

    public function key(): string
    {
        return 'safety_backup';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        return $context->restore->safety_copy !== false;
    }

    public function run(RestoreContext $context): void
    {
        // A database restore saves the database it is about to replace: the *chosen* database's
        // target, which for an archived backup restored elsewhere is not the deleted one's.
        $target = $context->isDatabaseRestore()
            ? $context->database?->backupTarget
            : $context->backup->target;

        if ($target === null) {
            throw new RuntimeException('the backup settings this backup came from no longer exist');
        }

        // The whole configured target, not just the half being restored: a half backup is a half
        // way back. Flagged safety at creation, since a worker dying mid-upload would otherwise
        // leave an ordinary backup without the retention exemption. See BackupRunner::run().
        $safety = $this->backups->run($target, $context->restore->user_id, isSafety: true);

        // Out of disk space is reported as such: the user can fix it by freeing space or restoring
        // without a copy.
        if ($safety->status !== BackupStatus::Verified && $safety->reason === NotEnoughBackupSpace::REASON) {
            throw new NotEnoughBackupSpace('the safety backup did not fit on the disk, so nothing was restored');
        }

        if ($safety->status !== BackupStatus::Verified) {
            throw new RuntimeException(
                'the safety backup did not complete, so nothing was restored',
            );
        }

        $context->restore->update(['safety_backup_id' => $safety->id]);

        $this->pruneOlderSafetyBackups($target->id, [$safety->id, $context->backup->id]);
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // The safety backup outlives the restore on purpose: it is the way back.
    }

    /**
     * Safety backups are exempt from the target's retention, so they need their own bound.
     *
     * Goes through DeleteBackup (archive first, then the row), like retention; deleting only the
     * rows left archives in the bucket that nothing in the panel could see or remove.
     *
     * An archive that will not delete keeps its row, visible and retried by the next restore, and
     * does not fail this one.
     */
    /**
     * @param  list<int>  $keep  the new safety backup and the backup being restored (an undo restores a safety backup, so pruning it would delete the archive being extracted)
     */
    private function pruneOlderSafetyBackups(int $targetId, array $keep): void
    {
        // Also keep any backup another queued or running restore will read.
        $inUse = Restore::query()
            ->whereIn('status', [RestoreStatus::Pending, RestoreStatus::Running])
            ->pluck('backup_id')
            ->all();

        $expired = Backup::query()
            ->where('backup_target_id', $targetId)
            ->where('is_safety', true)
            ->whereKeyNot(array_values(array_unique([...$keep, ...$inUse])))
            ->orderByDesc('id')
            ->skip(self::KEEP - 1)
            ->take(100)
            ->get();

        foreach ($expired as $backup) {
            try {
                $this->deleteBackup->execute($backup);
            } catch (Throwable $e) {
                Log::channel('server-ops')->warning('an old safety backup could not be deleted', [
                    'feature' => 'backup',
                    'backup' => $backup->id,
                    'detail' => $e->getMessage(),
                ]);
            }
        }
    }
}
