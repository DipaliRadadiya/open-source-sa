<?php

namespace App\Actions\Server\Backup;

use App\Models\Application;
use App\Models\BackupTarget;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\RetentionEnforcer;
use App\Support\ActivityChanges;

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
        $before = $existing ? $this->logged($existing) : [];

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
            [
                'name' => $application->name,
                'frequency' => $target->frequency,
                // Only for an edit: a first save has nothing to compare with.
                'changes' => $existing !== null
                    ? ActivityChanges::between($before, $this->logged($target->fresh(['storageDestination'])), self::LOGGED)
                    : null,
            ],
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

    /** What a backup settings entry compares, as a person reads it. */
    private const LOGGED = ['storage_destination', 'type', 'frequency', 'schedule_time', 'retention_count', 'enabled', 'file_excludes'];

    /**
     * @return array<string, mixed>
     */
    private function logged(BackupTarget $target): array
    {
        return [
            // The destination by name: an id means nothing in a log.
            'storage_destination' => $target->storageDestination?->name,
            'type' => $target->type instanceof \BackedEnum ? $target->type->value : $target->type,
            'frequency' => $target->frequency instanceof \BackedEnum ? $target->frequency->value : $target->frequency,
            'schedule_time' => $target->schedule_time,
            'retention_count' => $target->retention_count,
            'enabled' => (bool) $target->enabled,
            'file_excludes' => implode(', ', (array) ($target->file_excludes ?? [])),
        ];
    }
}
