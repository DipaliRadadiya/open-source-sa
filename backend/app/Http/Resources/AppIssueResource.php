<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read string $type
 * @property-read string $severity
 * @property-read string $message
 * @property-read array<string, mixed> $meta
 */
class AppIssueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource['type'],
            'severity' => $this->resource['severity'],
            'message' => $this->resource['message'],
            'meta' => $this->resource['meta'],
        ];
    }
}
