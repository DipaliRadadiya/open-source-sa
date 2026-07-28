<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected git account. The token is deliberately absent — not masked,
 * not partially shown: it is write-only. Rotation replaces it.
 */
class GitAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'provider_title' => __("git.providers.{$this->provider}"),
            'label' => $this->label,
            'identifier' => $this->identifier,
            'host' => $this->host,
            'workspace' => $this->workspace,
            'scopes' => $this->scopes ?? [],
            'last_verified_at' => $this->last_verified_at?->format('d-m-Y H:i:s'),
            'last_verified_at_human' => $this->last_verified_at?->diffForHumans(),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
