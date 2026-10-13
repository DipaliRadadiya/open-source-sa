<?php

namespace App\Http\Resources;

use App\Models\ApplicationUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;

/** @mixin ApplicationUpdate */
class ApplicationUpdateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'application_id' => $this->resource->application_id,
            'from_version' => $this->resource->from_version,
            'to_version' => $this->resource->to_version,
            'status' => $this->resource->status->value,
            'status_title' => $this->resource->status->label(),
            'reason' => $this->resource->reason,
            // A step can fail for a reason the provisioner names and this
            // feature has no sentence for; fall back rather than show a key.
            'reason_title' => match (true) {
                $this->resource->reason === null => null,
                Lang::has('app_update.reasons.'.$this->resource->reason) => __('app_update.reasons.'.$this->resource->reason),
                default => __('app_update.reasons.step_failed'),
            },
            'step' => $this->resource->step,
            'reference' => $this->resource->reference,
            'user' => $this->resource->user?->username,
            'started_at' => $this->resource->started_at?->format('d-m-Y H:i:s'),
            'finished_at' => $this->resource->finished_at?->format('d-m-Y H:i:s'),
            'created_at' => $this->resource->created_at?->format('d-m-Y H:i:s'),
        ];
    }
}
