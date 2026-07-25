<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'home_path' => $this->home_path,
            'shell' => $this->shell,
            // Index eager-loads only id+name; show eager-loads full detail.
            'applications' => $this->whenLoaded('applications', fn () => $this->applications->map(fn ($app) => array_filter([
                'id' => $app->id,
                'name' => $app->name,
                'domain' => $app->domain,
                'site_type' => $app->site_type,
                'php_version' => $app->php_version,
                'status' => $app->status,
            ], fn ($v) => $v !== null))->all()),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
