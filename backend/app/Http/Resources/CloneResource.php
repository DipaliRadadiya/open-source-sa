<?php

namespace App\Http\Resources;

use App\Models\Clone as CloneModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CloneModel */
class CloneResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $steps = ['provisioning', 'copying_files', 'cloning_database', 'starting_process'];
        $index = $this->resource->current_step === null
            ? false
            : array_search($this->resource->current_step, $steps, true);

        return [
            'id' => $this->resource->id,
            'source_application_id' => $this->resource->source_application_id,
            'source_application_name' => $this->resource->sourceApplication?->name,
            'target_application_id' => $this->resource->target_application_id,
            'name' => $this->resource->name,
            'domain' => $this->resource->domain,
            'status' => $this->resource->status->value,
            'status_title' => $this->resource->status->label(),
            'current_step' => $this->resource->current_step,
            'current_step_title' => $this->resource->current_step === null
                ? null
                : __('clone.current_step.'.$this->resource->current_step),
            'step_number' => $index === false ? null : $index + 1,
            'total_steps' => count($steps),
            'reason' => $this->resource->reason,
            'reason_title' => $this->resource->reason === null
                ? null
                : __('clone.cloning_errors.'.$this->resource->reason),
            'reference' => $this->resource->reference,
            'started_at' => $this->resource->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->resource->started_at?->diffForHumans(),
            'finished_at' => $this->resource->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $this->resource->finished_at?->diffForHumans(),
        ];
    }
}
