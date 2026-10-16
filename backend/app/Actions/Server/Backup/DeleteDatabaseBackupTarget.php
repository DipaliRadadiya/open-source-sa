<?php

namespace App\Actions\Server\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Services\ActivityLogger;
use Illuminate\Validation\ValidationException;

/**
 * Stop backing a database up. The same rules as {@see DeleteBackupTarget}:
 * refused while a run is in flight, and while backups exist unless they are
 * deleted too — archive first, through the single-delete path.
 */
class DeleteDatabaseBackupTarget
{
    public function __construct(
        private DeleteBackup $deleteBackup,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Database $database, bool $deleteBackups = false): void
    {
        $target = BackupTarget::where('database_id', $database->id)->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'database' => [__('backup.errors.not_configured')],
            ]);
        }

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

        foreach ($backups as $backup) {
            $this->deleteBackup->execute($backup);
        }

        $this->activityLogger->log('backup.target_deleted', $database, [
            'name' => $database->name,
            'backups_deleted' => $backups->count(),
        ]);

        $target->delete();
    }
}
