<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DatabaseConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'engine' => $this->engine,
            'driver' => (string) config("server.databases.engines.{$this->engine}.driver", 'sql'),
            'connection_type' => $this->connection_type,
            'host' => $this->host,
            'port' => $this->port,
            'socket' => $this->socket,
            'username' => $this->username,
            // Never return the admin password — only whether one is set.
            'has_password' => ($this->password ?? '') !== '',
            // MySQL/MariaDB root: whether the panel knows a root password (set
            // or saved by the user). Null on engines that have no such account.
            'has_root_password' => in_array($this->engine, ['mysql', 'mariadb'], true)
                ? ($this->root_password ?? '') !== ''
                : null,
            'options' => $this->options ?? [],
            // The controller merges `reachable` in after a live probe.
        ];
    }
}
