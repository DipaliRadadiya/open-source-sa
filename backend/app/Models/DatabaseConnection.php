<?php

namespace App\Models;

use App\Support\ProbeCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The panel's per-engine admin connection (mysql|mariadb|mongodb). Used to
 * run DDL as an isolated maintenance account. Password is encrypted at rest.
 */
#[Fillable(['engine', 'connection_type', 'host', 'port', 'socket', 'username', 'password', 'options'])]
class DatabaseConnection extends Model
{
    /** A changed connection changes what the engine list can see (FS-C46). */
    protected static function booted(): void
    {
        static::saved(fn () => ProbeCache::flush());
    }

    /**
     * The resource says only whether a root password is set.
     *
     * @var list<string>
     */
    protected $hidden = ['root_password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            // MySQL/MariaDB root, set or saved by the user. Not fillable: it is
            // written only after the server has accepted it.
            'root_password' => 'encrypted',
            'options' => 'array',
        ];
    }
}
