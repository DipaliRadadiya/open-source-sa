<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single permission in the catalog — the menu of what *can* be granted to a
 * role. Metadata only; no view/manage grant state (a role's own grants come
 * back from the roles endpoint).
 */
class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'level' => $this->level,
            'sub_level' => $this->sub_level,
            'name' => $this->name,
            'title' => $this->localizedTitle(),
            'icon' => $this->icon,
            'url' => $this->url,
        ];
    }
}
