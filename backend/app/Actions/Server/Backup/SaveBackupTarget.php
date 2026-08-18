<?php

namespace App\Actions\Server\Backup;

use App\Models\Application;
use App\Models\BackupTarget;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\RetentionEnforcer;

class SaveBackupTarget
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private RetentionEnforcer $retention,
    ) {}

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

        // Applied here rather than only at the end of the next backup. Lowering
        // retention from ten to three used to delete nothing until another run
        // happened — the setting saved, the screen agreed, and ten copies
        // stayed where they were. Only ever *lowered*: raising it deletes
        // nothing, so there is no case where saving this screen removes more
        // than the user just asked to keep.
        if ($existing !== null && (int) $target->retention_count < (int) $existing->retention_count) {
            $this->retention->apply($target);
        }

        return $target->fresh(['storageDestination']);
    }
}
