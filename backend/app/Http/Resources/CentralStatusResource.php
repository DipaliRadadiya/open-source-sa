<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CentralStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'enabled' => $this->resource['enabled'],
            'token' => $this->resource['enabled']
                ? $this->resource['masked']
                : null,
        ];
    }
}
