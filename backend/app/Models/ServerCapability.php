<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The single row describing this server. See the migration for why `stack`
 * and `capabilities` are separate.
 */
#[Fillable(['stack', 'web_server', 'capabilities', 'source', 'verified_at'])]
class ServerCapability extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function can(string $capability): bool
    {
        return (bool) ($this->capabilities[$capability] ?? false);
    }
}
