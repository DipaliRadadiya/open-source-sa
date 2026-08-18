<?php

namespace App\Actions\Server\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\Steps\PruneOldBackups;
use App\Services\Server\Backups\Storage\DestinationDisk;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Delete one backup: the archive first, then the record of it.
 *
 * That order is the whole of it, and it is the same order
 * {@see PruneOldBackups} uses — deliberately,
 * because the two are the same operation reached from different directions and
 * the day they disagree is the day one of them starts lying.
 *
 * Delete the row first and a failure leaves an object in someone's bucket that
 * nothing in the panel knows about: unfindable, undeletable, and billed for
 * every month until a human goes looking. Delete the archive first and a failure
 * leaves a row pointing at something already gone, which is visible, retryable
 * and free.
 *
 * Where retention *skips* what it cannot delete and moves on — a cost problem is
 * not worth stopping a backup run for — this refuses. Someone pressed delete and
 * is owed a straight answer about whether it happened.
 */
class DeleteBackup
{
    public function __construct(
        private DestinationDisk $disks,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(Backup $backup): void
    {
        // A run in flight is writing to the very key this would delete. The
        // uploader would then finish against a row that no longer exists and
        // leave the artefact behind — the orphan this class exists to avoid.
        if (in_array($backup->status, [BackupStatus::Pending, BackupStatus::Running, BackupStatus::Verifying], true)) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.delete_running')],
            ]);
        }

        $key = $backup->manifest['key'] ?? null;
        $destination = $backup->target?->storageDestination;

        if (is_string($key) && $key !== '' && $destination !== null) {
            try {
                $disk = $this->disks->for($destination);

                // `exists` first so a backup whose archive was already removed
                // by hand still deletes cleanly rather than failing forever on
                // something that is not there.
                if ($disk->exists($key)) {
                    $disk->delete($key);
                }
            } catch (Throwable $e) {
                report($e);

                throw ValidationException::withMessages([
                    'backup' => [__('backup.errors.delete_artifact')],
                ]);
            }
        }

        // Recorded before the row goes, because afterwards there is nothing left
        // to name it with.
        // The backup's own application, not the target's: a target can be gone
        // by the time its last backup is cleaned up, and the log entry still
        // has to be able to name the site.
        $this->activityLogger->log('backup.deleted', $backup->application, [
            'backup' => $backup->id,
            'started_at' => (string) $backup->created_at,
            // A safety backup is the parachute from a bad restore. Deleting one
            // is allowed — it is the user's data and this is an explicit act —
            // but it is worth being able to find in the log afterwards.
            'is_safety' => (bool) $backup->is_safety,
        ]);

        $backup->delete();
    }
}
