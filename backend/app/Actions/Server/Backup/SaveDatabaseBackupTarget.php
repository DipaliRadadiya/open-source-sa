<?php

namespace App\Actions\Server\Backup;

use App\Enums\BackupType;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\RetentionEnforcer;

/**
 * Create or edit the backup schedule of a database on its own — the database
 * half of {@see SaveBackupTarget}, with the same retention rule.
 */
class SaveDatabaseBackupTarget
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private RetentionEnforcer $retention,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Database $database, array $data): BackupTarget
    {
        $existing = BackupTarget::where('database_id', $database->id)->first();

        // One per database (unique index), and always `database`: a database
        // has no files, and the other types would archive nothing or the site
        // of somebody else.
        $target = BackupTarget::updateOrCreate(
            ['database_id' => $database->id],
            ['application_id' => null, 'type' => BackupType::Database->value] + $data,
        );

        $this->activityLogger->log(
            $existing === null ? 'backup.configured' : 'backup.updated',
            $target,
            ['name' => $database->name, 'frequency' => $target->frequency],
        );

        // As for a site: lowering retention applies now, raising it deletes nothing.
        if ($existing !== null && (int) $target->retention_count < (int) $existing->retention_count) {
            $this->retention->apply($target);
        }

        return $target->fresh(['storageDestination', 'database']);
    }
}
