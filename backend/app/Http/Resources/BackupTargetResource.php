<?php

namespace App\Http\Resources;

use App\Models\BackupTarget;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BackupTarget */
class BackupTargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $nextRun = $this->nextRunAt();
        $nextRun = $nextRun === null ? null : Carbon::instance($nextRun);

        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'database_id' => $this->database_id,
            'storage_destination_id' => $this->storage_destination_id,
            'storage_destination_name' => $this->whenLoaded(
                'storageDestination',
                fn (): ?string => $this->storageDestination?->name,
            ),
            'type' => $this->type->value,
            'type_title' => __('backup.type.'.$this->type->value),
            'retention_count' => $this->retention_count,
            'frequency' => $this->frequency,
            'frequency_title' => __('backup.frequency.'.$this->frequency),
            'schedule_time' => $this->schedule_time,
            'enabled' => $this->enabled,
            'file_excludes' => $this->file_excludes ?? [],
            'database_excludes' => $this->database_excludes ?? [],
            'last_run_at' => $this->last_run_at?->format('d-m-Y H:i:s'),
            'last_run_at_human' => $this->last_run_at?->diffForHumans(),
            // The newest good backup still kept, null once all are deleted; `last_run_at` is the
            // scheduler's own record and outlives them.
            'last_backup_at' => $this->latestKeptBackup?->finished_at?->format('d-m-Y H:i:s'),
            'last_backup_at_human' => $this->latestKeptBackup?->finished_at?->diffForHumans(),
            // Computed, never a cron string. Null when the target is manual or disabled.
            'next_run_at' => $nextRun?->format('d-m-Y H:i:s'),
            'next_run_at_human' => $nextRun?->diffForHumans(),
            // The clock `schedule_time` and `next_run_at` are in, so they are not read as local
            // time. Same name and IANA format as CronjobResource, but the *value* differs on
            // purpose: see BackupTarget::scheduleTimezone().
            'timezone' => $this->scheduleTimezone(),
            // The scheduler picks it up on its next tick. True for a target that has never run
            // (the first backup is immediate, not at the next slot).
            'is_due' => $this->isDue(now()),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'updated_at' => $this->updated_at?->format('d-m-Y H:i:s'),
        ];
    }
}
