<?php

namespace App\Http\Resources;

use App\Models\Restore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Restore $resource
 */
class RestoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $steps = (array) config('server.backups.restore_steps', []);
        $keys = array_map(fn (string $class): string => app($class)->key(), $steps);
        $index = $this->resource->current_step === null
            ? false
            : array_search($this->resource->current_step, $keys, true);

        return [
            'id' => $this->resource->id,
            'backup_id' => $this->resource->backup_id,
            'application_id' => $this->resource->application_id,
            'type' => $this->resource->type->value,
            'type_title' => __('backup.type.'.$this->resource->type->value),
            'status' => $this->resource->status->value,
            'status_title' => $this->resource->status->label(),
            'current_step' => $this->resource->current_step,
            'current_step_title' => $this->resource->current_step === null
                ? null
                : __('backup.restore_steps.'.$this->resource->current_step),
            // Position in the sequence, so the UI draws a bar without
            // hardcoding a step list that changes.
            'step_number' => $index === false ? null : $index + 1,
            'total_steps' => count($keys),
            // A stable key naming the step that failed, never raw stderr.
            'reason' => $this->resource->reason,
            'reason_title' => $this->resource->reason === null
                ? null
                : __('backup.restore_errors.'.$this->resource->reason),
            // The way back. Worth surfacing prominently after a restore, not
            // buried — it is the answer to "that was the wrong one".
            'safety_backup_id' => $this->resource->safety_backup_id,
            'rollback_path' => $this->resource->rollback_path,
            'reference' => $this->resource->reference,
            'started_at' => $this->resource->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->resource->started_at?->diffForHumans(),
            'finished_at' => $this->resource->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $this->resource->finished_at?->diffForHumans(),
        ];
    }
}
