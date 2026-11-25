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
 * Runs last, and only after this backup verified, so a failed run can never leave fewer
 * backups than the user asked to keep. Only *verified* backups count towards retention.
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
        // Not for a restore's safety backup: it would count as one of the N kept and push out the
        // oldest regular backup. Safety backups have their own limit (SafetyBackup::KEEP).
        return $context->target->retention_count > 0 && ! $context->backup->is_safety;
    }

    public function run(BackupContext $context): void
    {
        $keep = $context->target->retention_count;

        $expired = Backup::query()
            ->where('backup_target_id', $context->target->id)
            ->where('status', BackupStatus::Verified->value)
            // Never prune safety backups: they are the way back from a wrong restore.
            ->where('is_safety', false)
            ->whereKeyNot($context->backup->id)
            ->orderByDesc('id')
            // Skip the ones we keep; the rest is surplus. The current backup counts as one of them.
            ->skip(max(0, $keep - 1))
            ->take(1000)
            ->get();

        $pruned = 0;
        // Memoised per destination: building a disk parses credentials.
        $disks = [];

        foreach ($expired as $backup) {
            $key = $backup->manifest['key'] ?? null;

            // Use each backup's own destination, not the target's current one: after a repoint,
            // `exists()` would ask the new bucket, delete nothing and still drop the row.
            $destination = $backup->destination();

            if ($destination === null) {
                Log::channel('server-ops')->warning('backup prune skipped a backup with no destination', [
                    'feature' => 'backup',
                    'backup' => $backup->id,
                    'key' => $key,
                ]);

                continue;
            }

            try {
                // Inside the try: an unreachable destination of an *old* backup must not fail this run.
                $disk = $disks[$destination->id] ??= $this->disks->for($destination);

                if (is_string($key) && $disk->exists($key)) {
                    $disk->delete($key);
                }
            } catch (Throwable $e) {
                // Keep the row: dropping it would orphan the remote object for good.
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
