<?php

namespace App\Actions\Server\Backup;

use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Services\ActivityLogger;
use Illuminate\Validation\ValidationException;

/**
 * Stop backing a site up, and decide what happens to what has already been
 * taken.
 *
 * Deleting the target is not the destructive part — the archives are. The row
 * carries a schedule and a destination, and losing it costs a minute to retype.
 * The backups it produced are the only copy of somebody's site.
 *
 * So the target refuses to go while backups still reference it, naming how many,
 * unless the caller says explicitly that those go too. That is the same shape as
 * the storage-destination guard: refuse and name what is in the way, rather than
 * cascade quietly or fail with a foreign-key error that names nothing.
 */
class DeleteBackupTarget
{
    public function __construct(
        private DeleteBackup $deleteBackup,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(Application $application, bool $deleteBackups = false): void
    {
        $target = BackupTarget::where('application_id', $application->id)->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.not_configured')],
            ]);
        }

        // A run in flight would finish against a target that no longer exists,
        // and its uploaded archive would belong to nothing.
        $inFlight = Backup::query()
            ->where('backup_target_id', $target->id)
            ->whereIn('status', [BackupStatus::Pending->value, BackupStatus::Running->value, BackupStatus::Verifying->value])
            ->exists();

        if ($inFlight) {
            throw ValidationException::withMessages([
                'backup_target' => [__('backup.errors.delete_target_running')],
            ]);
        }

        $backups = Backup::query()->where('backup_target_id', $target->id)->orderBy('id')->get();

        if ($backups->isNotEmpty() && ! $deleteBackups) {
            throw ValidationException::withMessages([
                'backup_target' => [__('backup.errors.delete_target_has_backups', ['count' => $backups->count()])],
            ]);
        }

        // One at a time, through the same action the single delete uses, so an
        // archive that cannot be removed stops the whole thing with a real
        // message. Deleting the target anyway would strand every remaining
        // object in the bucket with nothing left pointing at it.
        foreach ($backups as $backup) {
            $this->deleteBackup->execute($backup);
        }

        $this->activityLogger->log('backup.target_deleted', $application, [
            'name' => $application->name,
            'backups_deleted' => $backups->count(),
        ]);

        $target->delete();
    }
}
