<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A database user, scoped to exactly one database. Password encrypted at rest
 * but decryptable for the connection string. `host` is `localhost`, a remote
 * IP/CIDR, or `%` depending on `connection_preference`.
 */
#[Fillable(['database_id', 'username', 'password', 'connection_preference', 'host'])]
class DatabaseUser extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }
}
