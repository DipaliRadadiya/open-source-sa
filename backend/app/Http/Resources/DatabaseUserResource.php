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

        // The password is a working credential: only someone who may manage databases gets it,
        // never a read-only role that can merely view the users list.
        $secret = $this->password !== null && (bool) $request->user()?->canManage('database');

        return [
            'id' => $this->id,
            'database_id' => $this->database_id,
            'username' => $this->username,
            // Null for a user adopted from a migrated server (the engine holds only a hash) and for
            // a caller without `database` manage; `password_known` still says whether one exists.
            'password' => $secret ? $this->password : null,
            'password_known' => $this->password !== null,
            'connection_preference' => $this->connection_preference,
            'host' => $this->host,
            // Null rather than a string with an empty password: one that looks right and does not
            // work is worse than none.
            'connection_string' => $database && $secret ? $this->connectionString() : null,
            // Where to connect, for every role that may see the user. Not secrets.
            'connection' => $database ? [
                'host' => $this->connectHost(),
                'port' => (int) config("server.databases.engines.{$this->database->engine}.default_port"),
                'database' => $this->database->name,
            ] : null,
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }

    private function connectionString(): string
    {
        $engine = $this->database->engine;
        $scheme = (string) config("server.databases.engines.{$engine}.uri_scheme");
        $port = (int) config("server.databases.engines.{$engine}.default_port");
        $host = $this->connectHost();

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

    /**
     * The address to connect TO (a remote user's `host` is where it may connect FROM). Local
     * users connect over loopback; anyone else needs this server's public address, with
     * loopback as the fallback when it cannot be found.
     */
    private function connectHost(): string
    {
        return $this->connection_preference === 'localhost'
            ? '127.0.0.1'
            : (app(ServerPublicIp::class)->detect(fn () => app(DnsVerifier::class)->serverIp()) ?? '127.0.0.1');
    }
}
