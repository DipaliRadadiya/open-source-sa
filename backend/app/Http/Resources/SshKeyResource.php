<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SshKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fingerprint' => $this->fingerprint,
            // The algorithm, from the key's own first word: `ssh-ed25519`,
            // `ssh-rsa`, `sk-ssh-ed25519@openssh.com`, …
            'type' => strtok((string) $this->public_key, " \t") ?: null,
            'system_user' => $this->whenLoaded('systemUser', fn () => [
                'id' => $this->systemUser->id,
                'username' => $this->systemUser->username,
            ]),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
