<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SyncRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'finished' => $this->status->finished(),
            'options' => $this->options ?? [],
            // Per-type counts, so a finished run renders without the client
            // counting several thousand items itself.
            'totals' => $this->totals ?? [],
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'resource_type' => $item->resource_type,
                'resource_key' => $item->resource_key,
                'action' => $item->action->value,
                'confidence' => $item->confidence,
                'evidence' => $item->evidence,
                // Localized here so the frontend never holds a reason list.
                'reason' => $item->reason ? __('sync.reasons.'.$item->reason) : null,
                'model_id' => $item->model_id,
            ])->all()),
        ];
    }
}
