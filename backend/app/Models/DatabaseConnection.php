<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The panel's per-engine admin connection (mysql|mariadb|mongodb). Used to
 * run DDL as an isolated maintenance account. Password is encrypted at rest.
 */
#[Fillable(['engine', 'connection_type', 'host', 'port', 'socket', 'username', 'password', 'options'])]
class DatabaseConnection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'options' => 'array',
        ];
    }
}
