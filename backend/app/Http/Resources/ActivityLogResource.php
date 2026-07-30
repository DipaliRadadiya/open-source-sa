<?php

namespace App\Http\Resources;

use App\Services\ActivityScopes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'action' => $this->action,
            // Which half of the panel this row is about, so the frontend
            // can badge or group without keeping its own copy of the map.
            'scope' => app(ActivityScopes::class)->for($this->type),
            'description' => __('activity.'.$this->type.'.'.$this->action, $this->properties ?? []),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'username' => $this->user->username,
            ] : null),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
