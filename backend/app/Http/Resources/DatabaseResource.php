<?php

namespace App\Http\Resources;

use App\Support\Bytes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DatabaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'engine' => $this->engine,
            'driver' => (string) config("server.databases.engines.{$this->engine}.driver", 'sql'),
            'charset' => $this->charset,
            'collation' => $this->collation,
            'application_id' => $this->application_id,
            'size_bytes' => (int) $this->size_bytes,
            'size_human' => Bytes::human((int) $this->size_bytes),
            'users_count' => $this->whenCounted('users'),
            'users' => DatabaseUserResource::collection($this->whenLoaded('users')),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
