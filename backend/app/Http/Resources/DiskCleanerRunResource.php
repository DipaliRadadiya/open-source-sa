<?php

namespace App\Http\Resources;

use App\Support\Bytes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiskCleanerRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trigger' => $this->trigger,
            'categories' => $this->categories ?? [],
            'freed' => $this->freed ?? [],
            'freed_total' => $this->freed_total,
            'freed_total_human' => Bytes::human((int) $this->freed_total),
            'status' => $this->status,
            'disk_percent' => $this->disk_percent,
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
