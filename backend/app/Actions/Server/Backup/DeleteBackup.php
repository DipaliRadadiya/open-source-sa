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
 * Delete one backup: the archive first, then the record of it (same order as {@see PruneOldBackups}).
 *
 * Row first would leave an object in the bucket that nothing in the panel knows about: unfindable
 * and billed. Archive first leaves, on failure, a visible, retryable row.
 *
 * Retention skips what it cannot delete; this refuses, because the user is owed a straight answer.
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
        // A run in flight writes to the very key this would delete, leaving an orphaned artefact.
        if (in_array($backup->status, [BackupStatus::Pending, BackupStatus::Running, BackupStatus::Verifying], true)) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.delete_running')],
            ]);
        }

        $key = $backup->manifest['key'] ?? null;
        $destination = $backup->destination();

        // A null `$destination` is unreachable: `backups` cascades on its target, and the target's
        // `storage_destination_id` is `restrictOnDelete`. Deliberately unguarded; recheck if an
        // application ever gets more than one independently deletable backup target.
        if (is_string($key) && $key !== '' && $destination !== null) {
            try {
                $disk = $this->disks->for($destination);

                // `exists` first so an archive already removed by hand does not fail forever.
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

        // Logged before the row goes. The backup's own application, not the target's: the target
        // can be gone by the time its last backup is cleaned up.
        $this->activityLogger->log('backup.deleted', $backup->application ?? $backup->database ?? $backup, [
            // The log sentence needs `:name` filled in.
            'name' => $backup->subjectName(),
            'backup' => $backup->id,
            'started_at' => (string) $backup->created_at,
            // Deleting a safety backup (the undo for a bad restore) is allowed, but must be findable.
            'is_safety' => (bool) $backup->is_safety,
        ]);

        $backup->delete();
    }
}
