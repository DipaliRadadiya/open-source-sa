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
                'installed' => $this->installed($engine, $version !== null),
                'charsets' => $this->driver($engine) === 'sql' ? (array) config('server.databases.charsets') : [],
            ];
        }, $this->engineNames());
    }

    /**
     * Is the engine present on this server, whether or not it is up?
     *
     * `running` is a live `SELECT VERSION()`, so a **stopped** engine and one
     * that was **never installed** both answer `running: false, version: null`
     * and are indistinguishable — while needing opposite advice: "start the
     * service" against "install it first". This separates them.
     *
     * An engine that answered is installed by definition, and that costs
     * nothing extra since the probe has already happened. Only silence is worth
     * asking the package manager about.
     *
     * MongoDB has no installer — it needs its own apt repository — so there is
     * no package name to query and it falls back to the client binary. That is
     * weaker evidence (a client can exist without a server), which is why it is
     * the fallback rather than the method.
     */
    private function installed(string $engine, bool $running): bool
    {
        if ($running) {
            return true;
        }

        $installers = app(Installers\EngineInstallerManager::class);

        if ($installers->canInstall($engine)) {
            return $installers->installer($engine)->installed();
        }

        $client = (string) config("server.databases.engines.{$engine}.client", '');

        if ($client === '') {
            return false;
        }

        // Array args through ServerOps, never a shell string — same rule as
        // every other command the panel runs.
        return $this->serverOps->run(
            ['which', $client],
            ['feature' => 'database', 'engine' => $engine, 'op' => 'detect_client'],
        )->ok;
    }

    public function isSystemDatabase(string $engine, string $name): bool
    {
        $key = $this->driver($engine) === 'mongo' ? 'mongo' : 'sql';

        return in_array($name, (array) config("server.databases.system_schemas.{$key}", []), true);
    }

    /**
     * Users the panel refuses to touch.
     *
     * The config list covers the engines' own accounts. The stored connection
     * usernames are added because the panel's *own* account — `panel_xxxxxxxxxx`,
     * created by the engine installer — is otherwise an ordinary-looking user in
     * the Database Users list, and deleting it silently breaks every database
     * operation with no way back through the UI.
     */
    public function isSystemUser(string $username): bool
    {
        if (in_array($username, (array) config('server.databases.system_users', []), true)) {
            return true;
        }

        return DatabaseConnection::query()->where('username', $username)->exists();
    }

    private function assertKnownEngine(string $engine): void
    {
        if (! in_array($engine, $this->engineNames(), true)) {
            throw new InvalidArgumentException("Unknown database engine [{$engine}].");
        }
    }
}
