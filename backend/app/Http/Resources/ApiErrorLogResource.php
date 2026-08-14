<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiErrorLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'occurred_at' => $this['datetime'] ?? null,
            'status' => $this['context']['status'] ?? null,
            'method' => $this['context']['method'] ?? null,
            'route' => $this['context']['route'] ?? null,
            'exception' => $this['context']['exception'] ?? null,
            'message' => $this['context']['message'] ?? null,
            'reference' => $this['context']['reference'] ?? null,
            'user_id' => $this['context']['user_id'] ?? null,
        ];
    }
}
