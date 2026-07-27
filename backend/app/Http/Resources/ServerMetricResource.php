<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServerMetricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sampled_at' => $this->sampled_at?->format('d-m-Y H:i:s'),
            'cpu' => (float) $this->cpu_percent,
            'memory' => (float) $this->memory_percent,
            'swap' => (float) $this->swap_percent,
            'disk' => (float) $this->disk_percent,
            'load_1' => (float) $this->load_1,
            'load_5' => (float) $this->load_5,
            'load_15' => (float) $this->load_15,
            'net_in' => (int) $this->net_in,
            'net_out' => (int) $this->net_out,
        ];
    }
}
