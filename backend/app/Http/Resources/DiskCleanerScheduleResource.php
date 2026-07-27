<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiskCleanerScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'enabled' => (bool) $this->enabled,
            'frequency' => $this->frequency,
            'categories' => $this->categories ?? [],
            'threshold_percent' => $this->threshold_percent,
            'notify' => (bool) $this->notify,
            'last_run_at' => $this->last_run_at?->format('d-m-Y H:i:s'),
            'last_run_at_human' => $this->last_run_at?->diffForHumans(),
        ];
    }
}
