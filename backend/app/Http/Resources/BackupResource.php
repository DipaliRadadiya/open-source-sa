<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Backup */
class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'size_bytes' => $this->size_bytes,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'verified_at' => $this->verified_at?->format('d-m-Y H:i:s'),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
