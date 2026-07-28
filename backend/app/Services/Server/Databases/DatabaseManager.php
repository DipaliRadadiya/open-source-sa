<?php

namespace App\Services\Server\Databases;

use App\Contracts\DatabaseEngine;
use App\Models\DatabaseConnection;
use App\Services\Server\ServerOps;
use InvalidArgumentException;

/**
 * Resolves the right DatabaseEngine for a given engine name, auto-seeding a
 * sensible default admin connection (detect-don't-trust — the user then edits
 * + tests it). Also the single source of truth for the capability list and
 * the system-object guardrails.
 */
class DatabaseManager
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * @return array<int, string>
     */
    public function engineNames(): array
    {
        return array_keys((array) config('server.databases.engines', []));
    }

    public function driver(string $engine): string
    {
        return (string) config("server.databases.engines.{$engine}.driver", 'sql');
    }

    /**
     * The stored admin connection for an engine, seeded with defaults on first
     * use so the feature works out-of-the-box and can be configured after.
     */
    public function connection(string $engine): DatabaseConnection
    {
        $this->assertKnownEngine($engine);

        return DatabaseConnection::firstOrCreate(
            ['engine' => $engine],
            [
                'connection_type' => 'tcp',
                'host' => '127.0.0.1',
                'port' => (int) config("server.databases.engines.{$engine}.default_port"),
                'socket' => config("server.databases.engines.{$engine}.default_socket"),
                'username' => 'root',
            ],
        );
    }

    public function engine(string $engine): DatabaseEngine
    {
        $connection = $this->connection($engine);

        return $this->driver($engine) === 'mongo'
            ? new MongoEngine($connection, $this->serverOps)
            : new SqlEngine($connection, $this->serverOps);
    }

    /**
     * Capability list — one entry per supported engine (detect-don't-trust).
     *
     * @return array<int, array<string, mixed>>
     */
    public function capabilities(): array
    {
        return array_map(function (string $engine) {
            $engineObj = $this->engine($engine);
            $version = $engineObj->version();

            return [
                'engine' => $engine,
                'driver' => $this->driver($engine),
                'running' => $version !== null,
                'version' => $version,
                'charsets' => $this->driver($engine) === 'sql' ? (array) config('server.databases.charsets') : [],
            ];
        }, $this->engineNames());
    }

    public function isSystemDatabase(string $engine, string $name): bool
    {
        $key = $this->driver($engine) === 'mongo' ? 'mongo' : 'sql';

        return in_array($name, (array) config("server.databases.system_schemas.{$key}", []), true);
    }

    public function isSystemUser(string $username): bool
    {
        return in_array($username, (array) config('server.databases.system_users', []), true);
    }

    private function assertKnownEngine(string $engine): void
    {
        if (! in_array($engine, $this->engineNames(), true)) {
            throw new InvalidArgumentException("Unknown database engine [{$engine}].");
        }
    }
}
