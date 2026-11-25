<?php

namespace App\Services\Server\Databases;

use App\Contracts\DatabaseEngine;
use App\Models\DatabaseConnection;
use App\Services\Server\ServerOps;
use InvalidArgumentException;

/**
 * Resolves the DatabaseEngine for an engine name, seeding a default admin connection.
 * Also the source of truth for the capability list and the system-object guardrails.
 */
class DatabaseManager
{
    /**
     * Detected versions for the lifetime of THIS instance, keyed by engine.
     *
     * Deliberately not a shared cache (a stored answer about the server goes stale): the class is
     * not a singleton, so each request/job re-detects once and repeat asks cost nothing.
     *
     * @var array<string, ?string>|null
     */
    private ?array $detectedVersions = null;

    /**
     * The full capability list for this instance, memoised on the same terms.
     *
     * Separate from {@see $detectedVersions}: `installed()` asks the package manager about
     * every engine that did not answer.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $capabilities = null;

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

        // An unmatched driver must fail loudly, not fall through to a MySQL client.
        return match ($this->driver($engine)) {
            'mongo' => new MongoEngine($connection, $this->serverOps),
            'pgsql' => new PgsqlEngine($connection, $this->serverOps),
            'sql' => new SqlEngine($connection, $this->serverOps),
            default => throw new InvalidArgumentException(
                "No engine driver for [{$engine}] (driver [{$this->driver($engine)}])."
            ),
        };
    }

    /**
     * The live version of every engine, or null where it did not answer.
     *
     * The cheap half of {@see capabilities()}: one probe per engine, no package-manager calls.
     * Memoised per instance; see {@see $detectedVersions}.
     *
     * @return array<string, ?string>
     */
    public function detectedVersions(): array
    {
        return $this->detectedVersions ??= array_reduce(
            $this->engineNames(),
            function (array $versions, string $engine): array {
                $versions[$engine] = $this->engine($engine)->version();

                return $versions;
            },
            [],
        );
    }

    /**
     * Capability list — one entry per supported engine (detect-don't-trust).
     *
     * @return array<int, array<string, mixed>>
     */
    public function capabilities(): array
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        $versions = $this->detectedVersions();

        return $this->capabilities = array_map(function (string $engine) use ($versions) {
            $version = $versions[$engine];

            return [
                'engine' => $engine,
                'driver' => $this->driver($engine),
                'running' => $version !== null,
                'version' => $version,
                'installed' => $this->installed($engine, $version !== null),
                'charsets' => $this->charsets($engine),
                // Published so clients need not name engines in their own code.
                'supports_remote_users' => $this->supportsRemoteUsers($engine),
            ];
        }, $this->engineNames());
    }

    /**
     * Is the engine present on this server, whether or not it is up?
     *
     * A stopped engine and a never-installed one both answer `running: false`; this tells them
     * apart ("start the service" vs "install it first"). Only silent engines are asked about.
     *
     * MongoDB has no installer, so it falls back to the client binary: weaker evidence, since
     * a client can exist without a server.
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

        return $this->serverOps->run(
            ['which', $client],
            ['feature' => 'database', 'engine' => $engine, 'op' => 'detect_client'],
            expectedExitCodes: [1], // not on PATH: an answer
        )->ok;
    }

    /**
     * Is this engine's server package installed, asked of dpkg alone?
     *
     * For callers that must not probe an engine: probing "MySQL" on a MariaDB server logs an
     * "Access denied for user 'root'" warning each time.
     *
     * Not {@see installed()}: that falls back to the client binary, and MariaDB's client
     * answers to `mysql`.
     */
    public function serverInstalled(string $engine): bool
    {
        if (config("server.databases.engines.{$engine}.installer") === null) {
            return false;
        }

        return app(Installers\EngineInstallerManager::class)->installer($engine)->installed();
    }

    /**
     * Charset => allowed collations for this engine, empty where the concept
     * does not exist.
     *
     * @return array<string, array<int, string>>
     */
    public function charsets(string $engine): array
    {
        return (array) config("server.databases.drivers.{$this->driver($engine)}.charsets", []);
    }

    /**
     * Does `renameUser()` carry the password across?
     *
     * False means the driver recreates the account, so the new password is already applied.
     */
    public function renameKeepsPassword(string $engine): bool
    {
        return (bool) config("server.databases.drivers.{$this->driver($engine)}.rename_keeps_password", false);
    }

    /**
     * Can an account on this engine be reached from a named host?
     *
     * False where the host is not part of the account: a PostgreSQL role is cluster-wide and
     * access is decided by `pg_hba.conf`, which the panel does not manage.
     */
    public function supportsRemoteUsers(string $engine): bool
    {
        return (bool) config("server.databases.drivers.{$this->driver($engine)}.supports_remote_users", false);
    }

    /**
     * The engine's own databases — never created, dropped or altered.
     *
     * @return array<int, string>
     */
    public function systemSchemas(string $engine): array
    {
        return (array) config("server.databases.drivers.{$this->driver($engine)}.system_schemas", []);
    }

    /**
     * Every driver's system databases at once.
     *
     * For validation before the engine is known. Wider than one driver's list on purpose:
     * refusing `admin` as a MySQL name is cheaper than dropping `template1`.
     *
     * @return array<int, string>
     */
    public function allSystemSchemas(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(
                fn (array $driver): array => (array) ($driver['system_schemas'] ?? []),
                array_values((array) config('server.databases.drivers', [])),
            ),
        )));
    }

    public function isSystemDatabase(string $engine, string $name): bool
    {
        return in_array($name, $this->systemSchemas($engine), true);
    }

    /**
     * Users the panel refuses to touch.
     *
     * Includes the stored connection usernames: the panel's own `panel_xxxxxxxxxx` account would
     * otherwise look ordinary, and deleting it breaks every database operation.
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
