<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class CentralStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'enabled' => $this->resource['enabled'],
            'token' => $this->resource['enabled']
                ? $this->resource['masked']
                : null,
            // Enabled is "a key exists"; connected is "Central has used it"
            // (bug #49). Between the two the screen says it is waiting.
            'connected' => $this->resource['enabled'] && $this->resource['last_used_at'] !== null,
            'last_used_at' => $this->resource['enabled'] && $this->resource['last_used_at'] !== null
                ? Carbon::parse($this->resource['last_used_at'])->toIso8601String()
                : null,
        ];
    }
}
