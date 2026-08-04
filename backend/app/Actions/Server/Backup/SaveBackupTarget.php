<?php

namespace App\Actions\Server\Backup;

use App\Models\Application;
use App\Models\BackupTarget;
use App\Services\ActivityLogger;

class SaveBackupTarget
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Application $application, array $data): BackupTarget
    {
        $existing = BackupTarget::where('application_id', $application->id)->first();

        // updateOrCreate on application_id: the table holds one target per
        // application, so configuring backups twice is an edit, not a second
        // row that would violate the unique index.
        $target = BackupTarget::updateOrCreate(
            ['application_id' => $application->id],
            $data,
        );

        $this->activityLogger->log(
            $existing === null ? 'backup.configured' : 'backup.updated',
            $target,
            ['name' => $application->name, 'frequency' => $target->frequency],
        );

        return $target->fresh(['storageDestination']);
    }
}
