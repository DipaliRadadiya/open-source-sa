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
            'enabled' => $this->enabled,
            // Objects, not lists, would be wrong here: these genuinely are
            // ordered collections of patterns. `[]` is the right empty value.
            'file_excludes' => $this->file_excludes ?? [],
            'database_excludes' => $this->database_excludes ?? [],
            'last_run_at' => $this->last_run_at?->format('d-m-Y H:i:s'),
            'last_run_at_human' => $this->last_run_at?->diffForHumans(),
            // Computed, never a cron string: the schedule is one constant in
            // the model and stays there. Null when the target is manual or
            // disabled — there is no next run to promise.
            'next_run_at' => $nextRun?->format('d-m-Y H:i:s'),
            'next_run_at_human' => $nextRun?->diffForHumans(),
            // A run the scheduler will pick up on its next tick, within a
            // minute. True for a target that has never run — the first backup
            // is taken immediately rather than at tonight's slot, so a UI
            // showing only `next_run_at` would name tomorrow at exactly the
            // moment the user is watching the first one happen.
            'is_due' => $this->isDue(now()),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'updated_at' => $this->updated_at?->format('d-m-Y H:i:s'),
        ];
    }
}
