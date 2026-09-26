<?php

namespace App\Http\Resources;

use App\Services\Server\Applications\DnsVerifier;
use App\Services\Server\ServerPublicIp;
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
        $scheme = (string) config("server.databases.engines.{$engine}.uri_scheme");
        $port = (int) config("server.databases.engines.{$engine}.default_port");
        // The address to connect TO. A remote user's `host` is where it may
        // connect FROM, and printing that here (as this used to) handed
        // someone a string pointing at their own machine. A local user
        // connects over loopback; anyone else needs this server's public
        // address, and when that cannot be found out, loopback is the
        // honest fallback — it is at least this server.
        $host = $this->connection_preference === 'localhost'
            ? '127.0.0.1'
            : (app(ServerPublicIp::class)->detect(fn () => app(DnsVerifier::class)->serverIp()) ?? '127.0.0.1');

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
