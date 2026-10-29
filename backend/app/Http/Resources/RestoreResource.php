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

        // A restore told not to take a copy has one step fewer; "Step 2 of 6"
        // must not count a step that never runs.
        if ($this->resource->safety_copy === false) {
            $keys = array_values(array_diff($keys, ['safety_backup']));
        }
        $index = $this->resource->current_step === null
            ? false
            : array_search($this->resource->current_step, $keys, true);

        return [
            'id' => $this->resource->id,
            'backup_id' => $this->resource->backup_id,
            'application_id' => $this->resource->application_id,
            'application_name' => $this->resource->application?->name,
            'application_domain' => $this->resource->application?->domain,
            // RC-19: a database's own restore. Without these its Backups tab
            // could not find the restore again after a reload, and a database
            // restore looked like one of a deleted site (both have no
            // application). The name and engine come from the backup when the
            // database itself is gone, as the backup's own row keeps them.
            'database_id' => $this->resource->database_id,
            'database_name' => $this->databaseBackup()
                ? ($this->resource->database?->name ?? $this->resource->backup?->subjectName())
                : null,
            'database_engine' => $this->databaseBackup()
                ? ($this->resource->database?->engine ?? $this->resource->backup?->subject_engine)
                : null,
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
            // Whether a copy was asked for. False: no Undo for this restore.
            'safety_copy' => $this->resource->safety_copy !== false,
            'rollback_path' => $this->resource->rollback_path,
            'reference' => $this->resource->reference,
            // When it was asked for (OLD-8): a restore still queued has no
            // start time, and the row had no time at all.
            'created_at' => $this->resource->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->resource->created_at?->diffForHumans(),
            'started_at' => $this->resource->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->resource->started_at?->diffForHumans(),
            'finished_at' => $this->resource->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $this->resource->finished_at?->diffForHumans(),
        ];
    }

    /** A restore of a database on its own, not through a site. */
    private function databaseBackup(): bool
    {
        return $this->resource->database_id !== null || $this->resource->backup?->isDatabaseBackup() === true;
    }
}
