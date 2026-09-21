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
    /**
     * Detected versions for the lifetime of THIS instance, keyed by engine.
     *
     * Not a cache, and deliberately not one. The detect-don't-trust rule says a
     * stored answer about the server goes stale — so this is scoped to a single
     * object rather than to a key with a TTL. Nothing binds this class as a
     * singleton (see the test that pins that), so each injection point holds
     * its own and re-detects: a request, a controller, a queue job. Inside one
     * of those the engine cannot meaningfully change, and asking four times is
     * only four subprocesses saying the same thing.
     *
     * @var array<string, ?string>|null
     */
    private ?array $detectedVersions = null;

    /**
     * The full capability list for this instance, on the same terms.
     *
     * Separate from {@see $detectedVersions} because it is the more expensive
     * half: `installed()` asks the package manager about every engine that did
     * not answer, and that question does not short-circuit on repeat.
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

        // Matched on the driver rather than falling through to SqlEngine,
        // which is the shape the rest of this class was corrected to in
        // 3ceb3452: an unmatched driver must be a loud failure, not a MySQL
        // client pointed at an engine that does not speak MySQL.
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
     * The cheap half of {@see capabilities()}: one probe per engine and no
     * package-manager questions. Split out because the setup page reads only
     * `running` and `version` — it never looks at `installed`, and `installed`
     * is the `dpkg-query` half. Rendering that page used to cost 24 commands,
     * 12 of which were answering a question nobody asked.
     *
     * Memoised per instance, so the three calls the setup component makes
     * become one. See {@see $detectedVersions} for why that is not a cache.
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
                // Whether an account on this engine can be reached from
                // another host. False where the host is not part of the
                // account — a PostgreSQL role is cluster-wide, and which
                // addresses may reach it lives in `pg_hba.conf`, which the
                // panel does not manage.
                //
                // Published because the alternative is the client naming the
                // engine in its own code, which is exactly what 3ceb3452 took
                // out of this class: a fact the API already holds is not the
                // client's to re-derive.
                'supports_remote_users' => $this->supportsRemoteUsers($engine),
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
     * False means the driver recreates the account, so the new password is
     * already applied and a second `setPassword()` would be redundant.
     */
    public function renameKeepsPassword(string $engine): bool
    {
        return (bool) config("server.databases.drivers.{$this->driver($engine)}.rename_keeps_password", false);
    }

    /**
     * Can an account on this engine be reached from a named host?
     *
     * False where the host is not part of the account — a PostgreSQL role is
     * cluster-wide and access from an address is decided by `pg_hba.conf`,
     * which the panel does not manage. The request refuses rather than storing
     * a preference nothing applies.
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
     * For validation, which refuses a reserved name before the engine is
     * necessarily known — and must keep refusing it if the request later
     * names a different engine. Wider than one driver's list on purpose: the
     * cost is refusing `admin` as a MySQL database name, and the cost of the
     * other mistake is a dropped `template1`.
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
