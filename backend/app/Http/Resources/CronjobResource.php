<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CronjobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            // Present only when the target is a panel-managed System User.
            'system_user' => $this->whenLoaded('systemUser', fn () => $this->systemUser ? [
                'id' => $this->systemUser->id,
                'username' => $this->systemUser->username,
            ] : null),
            'command' => $this->command,
            'expression' => $this->expression,
            'active' => (bool) $this->active,
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
