<?php

namespace App\Services\Server\Backups;

use App\Actions\Server\Backup\DeleteBackup;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\BackupTarget;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bring a target down to its retention count now, rather than at the next run.
 *
 * {@see Steps\PruneOldBackups} only enforces retention after a backup, so lowering the
 * count would otherwise delete nothing until the next one.
 *
 * Same rules as the prune step: only *verified* backups count toward retention, and
 * safety backups are never counted or deleted (they are the way back from a bad restore).
 */
class RetentionEnforcer
{
    public function __construct(private DeleteBackup $deleteBackup) {}

    /**
     * @return int how many were deleted
     */
    public function apply(BackupTarget $target): int
    {
        $keep = (int) $target->retention_count;

        if ($keep <= 0) {
            // Zero means keep everything; reading it as "delete everything" is unrecoverable.
            return 0;
        }

        $expired = Backup::query()
            ->where('backup_target_id', $target->id)
            ->where('status', BackupStatus::Verified->value)
            ->where('is_safety', false)
            ->orderByDesc('id')
            ->skip($keep)
            ->take(1000)
            ->get();

        $deleted = 0;

        foreach ($expired as $backup) {
            try {
                $this->deleteBackup->execute($backup);
                $deleted++;
            } catch (Throwable $e) {
                // A side effect of saving a setting: a failed delete must not fail the save.
                // Logged; the row stays and is retried next time retention runs.
                Log::channel('server-ops')->warning('retention could not delete a backup', [
                    'feature' => 'backup',
                    'backup' => $backup->id,
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        return $deleted;
    }
}
