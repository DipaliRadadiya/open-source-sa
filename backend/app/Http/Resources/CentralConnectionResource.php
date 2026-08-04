<?php

namespace App\Http\Resources;

use App\Models\CentralConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CentralConnection $resource
 */
class CentralConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token = $this->resource->token();

        return [
            'connected' => $this->resource->revoked_at === null,
            'connected_at' => $this->resource->connected_at?->format('d-m-Y H:i:s'),
            'connected_at_human' => $this->resource->connected_at?->diffForHumans(),
            'connected_by' => $this->whenLoaded('connectedBy', fn () => [
                'id' => $this->resource->connectedBy?->id,
                'username' => $this->resource->connectedBy?->username,
            ]),
            // Whether the far end has ever actually used the key, and when it
            // last did. The one field that answers "is this integration live
            // or just switched on and forgotten?".
            'last_used_at' => $token?->last_used_at?->format('d-m-Y H:i:s'),
            'last_used_at_human' => $token?->last_used_at?->diffForHumans(),
            'revoked_at' => $this->resource->revoked_at?->format('d-m-Y H:i:s'),
            'revoked_at_human' => $this->resource->revoked_at?->diffForHumans(),
        ];
    }
}
