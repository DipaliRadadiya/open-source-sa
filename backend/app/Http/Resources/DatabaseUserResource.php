<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DatabaseUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $database = $this->whenLoaded('database');

        return [
            'id' => $this->id,
            'database_id' => $this->database_id,
            'username' => $this->username,
            // Decryptable + shown so the owner can build the connection string.
            // Null for a user adopted from a migrated server: the engine holds
            // a hash, and a hash is not a password.
            'password' => $this->password,
            'password_known' => $this->password !== null,
            'connection_preference' => $this->connection_preference,
            'host' => $this->host,
            // Null rather than a string with an empty password in it. A
            // connection string that looks right and does not work is worse
            // than none — it moves the confusion to somewhere much harder to
            // debug than this screen.
            'connection_string' => $database && $this->password !== null ? $this->connectionString() : null,
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }

    private function connectionString(): string
    {
        $engine = $this->database->engine;
        $scheme = (string) config("server.databases.engines.{$engine}.driver") === 'mongo' ? 'mongodb' : $engine;
        $port = (int) config("server.databases.engines.{$engine}.default_port");
        $host = in_array($this->host, ['localhost', '%'], true) ? '127.0.0.1' : $this->host;

        return sprintf(
            '%s://%s:%s@%s:%d/%s',
            $scheme,
            rawurlencode($this->username),
            rawurlencode((string) $this->password),
            $host,
            $port,
            $this->database->name,
        );
    }
}
