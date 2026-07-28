<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A connected git provider account. The token is encrypted at rest and is
 * never exposed through a Resource — rotation replaces it, it is never read
 * back by the client.
 */
#[Fillable(['provider', 'label', 'identifier', 'token', 'host', 'workspace', 'scopes', 'last_verified_at'])]
class GitAccount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'scopes' => 'array',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * Base URL for this account's API — the provider default unless this is a
     * self-hosted instance with its own host.
     */
    public function apiBaseUrl(): string
    {
        return rtrim($this->host ?: (string) config("server.git.providers.{$this->provider}.api"), '/');
    }
}
