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
            'options' => $this->options ?? [],
            // The controller merges `reachable` in after a live probe.
        ];
    }
}
