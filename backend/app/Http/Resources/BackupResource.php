<?php

namespace App\Http\Resources;

use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Backup */
class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'application_name' => $this->resource->application?->name,
            'application_domain' => $this->resource->application?->domain,
            'type' => $this->type->value,
            // Taken automatically just before a restore overwrote the site.
            // Worth marking in the list: it is the one entry someone scanning
            // timestamps after a bad restore is actually looking for, and it
            // is exempt from retention so it will not quietly disappear.
            'is_safety' => (bool) $this->is_safety,
            'status' => $this->status->value,
            'status_title' => __('backup.status.'.$this->status->value),
            'type_title' => __('backup.type.'.$this->type->value),
            // `reason` carries the step that failed — a stable key. The title
            // explains it in the viewer's language; the frontend branches on
            // the key, never on the prose.
            'reason_title' => $this->reason === null
                ? null
                : __('backup.errors.'.$this->reason),
            'size_bytes' => $this->size_bytes,
            'reason' => $this->reason,
            'log_key' => $this->log_key,
            'reference' => $this->reference,
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'verified_at' => $this->verified_at?->format('d-m-Y H:i:s'),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
