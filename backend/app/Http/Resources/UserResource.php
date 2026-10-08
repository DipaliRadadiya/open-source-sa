<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'is_admin' => (bool) $this->is_admin,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
            ])->all()),
            // OLD-17: null until the user's first sign-in after this was added.
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_at_human' => $this->last_login_at?->diffForHumans(),
            'last_login_ip' => $this->last_login_ip,
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
