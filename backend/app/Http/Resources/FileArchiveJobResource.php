<?php

namespace App\Http\Resources;

use App\Models\FileArchiveJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FileArchiveJob
 */
class FileArchiveJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'target' => $this->target,
            'status' => $this->status->value,
            'size_bytes' => $this->size_bytes,
            // Built here rather than stored, so a failure reads in the
            // *viewer's* locale instead of whoever started the job.
            'message' => $this->message(),
            'reference' => $this->reference,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
