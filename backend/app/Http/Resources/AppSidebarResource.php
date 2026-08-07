<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One navigation item in the app sidebar.
 *
 * @property-read string $name
 * @property-read string $title
 * @property-read string $url
 * @property-read string $sub_level
 * @property-read string $sub_level_title
 * @property-read string $icon
 */
class AppSidebarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource['name'],
            'title' => $this->resource['title'],
            'url' => $this->resource['url'],
            'sub_level' => $this->resource['sub_level'],
            'sub_level_title' => $this->resource['sub_level_title'],
            'icon' => $this->resource['icon'],
        ];
    }
}
