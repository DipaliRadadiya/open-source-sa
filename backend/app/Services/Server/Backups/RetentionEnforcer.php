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
 * {@see Steps\PruneOldBackups} enforces retention as the last step of a backup,
 * which is the right moment for the common case — but it means lowering
 * retention from ten to three deletes nothing until another backup happens. The
 * user changed a setting, was told it saved, and ten copies stayed where they
 * were. That is the panel reporting a state the server is not in, which is the
 * one thing this codebase tries hardest not to do.
 *
 * The rules are the prune step's, deliberately: only *verified* backups count
 * towards retention, because a failed run is not one of your three copies; and
 * safety backups are never counted or deleted, because a safety backup is the
 * only way back from a restore that turned out to be wrong.
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
            // Zero means keep everything. Treating it as "delete everything"
            // would be an unrecoverable reading of an ambiguous number.
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
                // Unlike a user pressing delete, nobody is waiting on this one:
                // it is a side effect of saving a setting. An archive that will
                // not delete must not turn a successful settings save into an
                // error, so it is logged and the row stays — visible, and
                // retried next time retention runs.
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
