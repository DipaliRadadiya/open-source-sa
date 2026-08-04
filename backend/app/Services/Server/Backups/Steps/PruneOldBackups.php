<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\Storage\DestinationDisk;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps only the newest `retention_count` verified backups.
 *
 * Runs last, and only after this backup verified, so the panel never deletes
 * an old backup to make room for one that then fails. That ordering is the
 * whole safety property: pruning first would mean a failed run could leave
 * fewer backups than the user asked to keep.
 *
 * Only *verified* backups count towards retention. A failed run is not one of
 * your three copies, and counting it as one would quietly erode the retention
 * the user configured.
 */
class PruneOldBackups implements BackupStep
{
    public function __construct(private DestinationDisk $disks) {}

    public function key(): string
    {
        return 'prune_old_backups';
    }

    public function appliesTo(BackupContext $context): bool
    {
        return $context->target->retention_count > 0;
    }

    public function run(BackupContext $context): void
    {
        $keep = $context->target->retention_count;

        $expired = Backup::query()
            ->where('backup_target_id', $context->target->id)
            ->where('status', BackupStatus::Verified->value)
            ->whereKeyNot($context->backup->id)
            ->orderByDesc('id')
            // Skip the ones we are keeping; everything after is surplus. The
            // current backup is excluded above and counted separately, so the
            // window is retention minus this one.
            ->skip(max(0, $keep - 1))
            ->take(1000)
            ->get();

        $disk = $this->disks->for($context->target->storageDestination);
        $pruned = 0;

        foreach ($expired as $backup) {
            $key = $backup->manifest['key'] ?? null;

            try {
                if (is_string($key) && $disk->exists($key)) {
                    $disk->delete($key);
                }
            } catch (Throwable $e) {
                // A remote object we cannot delete is a cost problem, not a
                // correctness one. Losing the row would orphan the object
                // permanently, so the row stays and the failure is logged.
                Log::channel('server-ops')->warning('backup prune could not delete a remote artefact', [
                    'feature' => 'backup',
                    'backup' => $backup->id,
                    'key' => $key,
                    'detail' => $e->getMessage(),
                ]);

                continue;
            }

            $backup->delete();
            $pruned++;
        }

        $context->manifest['pruned'] = $pruned;
    }

    public function cleanup(BackupContext $context): void
    {
        //
    }
}
