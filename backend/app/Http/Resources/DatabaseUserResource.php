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

        // The password is a working credential, so it goes only to someone
        // who may manage databases. It went to anyone who could *view* them
        // (DB-01): the users list sits behind `database` view, and a
        // read-only role got the plaintext password and a ready-made
        // connection string. The same bar downloading an export already had.
        $secret = $this->password !== null && (bool) $request->user()?->canManage('database');

        return [
            'id' => $this->id,
            'database_id' => $this->database_id,
            'username' => $this->username,
            // Decryptable + shown so the owner can build the connection string.
            // Null for a user adopted from a migrated server: the engine holds
            // a hash, and a hash is not a password.
            //
            // Null for a caller without `database` manage; `password_known`
            // still says whether one exists, so a read-only screen can say
            // "set" without being handed it.
            'password' => $secret ? $this->password : null,
            'password_known' => $this->password !== null,
            'connection_preference' => $this->connection_preference,
            'host' => $this->host,
            // Null rather than a string with an empty password in it. A
            // connection string that looks right and does not work is worse
            // than none — it moves the confusion to somewhere much harder to
            // debug than this screen.
            'connection_string' => $database && $secret ? $this->connectionString() : null,
            // Where to connect, for every role that may see the user (FS-B11).
            // Not secrets — and a view-only screen lost them along with the
            // connection string they used to be read out of.
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
     * The address to connect TO. A remote user's `host` is where it may
     * connect FROM, and printing that handed someone an address pointing at
     * their own machine. Local users connect over loopback; anyone else needs
     * this server's public address, and loopback is the honest fallback when
     * that cannot be found out — it is at least this server.
     */
    private function connectHost(): string
    {
        return $this->connection_preference === 'localhost'
            ? '127.0.0.1'
            : (app(ServerPublicIp::class)->detect(fn () => app(DnsVerifier::class)->serverIp()) ?? '127.0.0.1');
    }
}
