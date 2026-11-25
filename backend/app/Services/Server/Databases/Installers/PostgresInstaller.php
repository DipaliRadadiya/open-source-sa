<?php

namespace App\Services\Server\Databases\Installers;

use App\Contracts\EngineInstaller;
use App\Exceptions\Server\Database\EngineInstallException;
use App\Models\DatabaseConnection;
use App\Services\Server\Databases\DatabasePassword;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

/**
 * Installs PostgreSQL from Ubuntu's own archive and gives the panel an account.
 *
 * Standalone rather than an `AbstractSqlEngineInstaller` subclass, for two non-stylistic reasons:
 *
 *  - That class connects with `--protocol=socket --user=root`, which works on MySQL/MariaDB
 *    because `root@localhost` authenticates by being OS root. PostgreSQL uses **peer** auth and
 *    root is not `postgres`, so everything here goes through `runuser -u postgres`.
 *  - It refuses to install when the *other* SQL engine is present (they fight over 3306);
 *    PostgreSQL is on 5432 and coexists with both.
 *
 * **Health is never asked of systemd.** `postgresql.service` is a `Type=oneshot` with
 * `ExecStart=/bin/true` and `RemainAfterExit=on`, so `is-active` says `active` whatever the
 * clusters do; `postgresql@.service` starts with `ExecStart=-/usr/bin/pg_ctlcluster ...`, whose
 * leading `-` makes systemd ignore a failed start. Only `pg_isready` answers "is PostgreSQL up".
 *
 * Nothing here touches `postgresql.conf` or `pg_hba.conf`: Ubuntu's default hba already allows
 * scram-sha-256 from 127.0.0.1, which is what the stored connection uses.
 */
class PostgresInstaller implements EngineInstaller
{
    /**
     * `panel_` plus ten random characters.
     *
     * Random so no guessable superuser sits behind 5432 on every install; prefixed so an admin
     * reading `\du` can tell it is the panel's role (an opaque SUPERUSER looks like a backdoor
     * and would get dropped). A PostgreSQL identifier allows 63 bytes, so no length concern.
     */
    private const USERNAME_PREFIX = 'panel_';

    private const USERNAME_RANDOM = 10;

    public function __construct(private ServerOps $serverOps) {}

    public function engine(): string
    {
        return 'postgresql';
    }

    /**
     * Is the *server* here?
     *
     * `dpkg-query` on the server package, not "does psql exist": client packages are common on
     * boxes with no server (as in the MongoDB installer).
     */
    public function installed(): bool
    {
        $status = trim($this->serverOps->run(
            ['dpkg-query', '-W', '-f=${Status}', $this->serverPackage()],
            $this->context('detect'),
            // Exit 1: not installed, an answer, not a failure.
            expectedExitCodes: [1],
        )->output());

        return str_contains($status, 'install ok installed');
    }

    /**
     * @throws EngineInstallException
     */
    public function install(?callable $onStep = null, ?callable $onOutput = null, ?bool $wasAbsent = null): void
    {
        // Whether this install (not this attempt) put PostgreSQL here, as in MongoDB's: a failed
        // first attempt leaves the package present, so re-asking the box takes the brownfield path.
        $fresh = $wasAbsent ?? ! $this->installed();

        if ($fresh) {
            $this->report($onStep, 'preparing');
            $this->installPackages($onOutput, fn () => $this->report($onStep, 'waiting_for_package_manager'));
        }

        $this->startCluster($onStep);
        $this->report($onStep, 'creating_panel_account');
        $this->provisionPanelAccount();
    }

    /**
     * @throws EngineInstallException
     */
    private function installPackages(?callable $onOutput, ?callable $onWait = null): void
    {
        $result = $this->serverOps->apt(
            array_merge(['apt-get', 'install', '-y', '--no-install-recommends'], $this->packages()),
            $this->context('install'),
            timeout: (int) config('server.databases.install_timeout', 900),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
            onOutput: $onOutput,
            // A fresh box's unattended-upgrades holds the lock for minutes; say so on screen.
            onWait: $onWait,
        );

        if ($result->failed()) {
            throw EngineInstallException::because(
                $this->classify($result->errorOutput()),
                $result->reference,
            );
        }
    }

    /**
     * Start the cluster the package created, then prove it answers.
     *
     * The cluster is **discovered**, not assumed to be `16-main`: the version moves with the
     * distribution and a box can carry several.
     *
     * @throws EngineInstallException
     */
    private function startCluster(?callable $onStep): void
    {
        $this->report($onStep, 'starting_service');

        $cluster = $this->cluster();

        if ($cluster === null) {
            // Installed but no cluster (failed initdb, purged cluster): "start it again" is not the fix.
            throw EngineInstallException::because('cluster_missing', null);
        }

        foreach ([['enable', '--now'], ['restart']] as $args) {
            $this->serverOps->run(
                array_merge(['systemctl'], $args, ["postgresql@{$cluster}"]),
                $this->context('service'),
                timeout: 120,
            );
        }

        // systemctl cannot be trusted here (see the class docblock); prove it with pg_isready.
        $this->report($onStep, 'verifying_cluster');
        $ready = $this->isReady();

        if ($ready->failed()) {
            throw EngineInstallException::because('unreachable', $ready->reference);
        }
    }

    /**
     * The first cluster `pg_lsclusters` reports, as `<version>-<name>`.
     *
     * Parsed with `--no-header`; the first two columns are version and name.
     */
    private function cluster(): ?string
    {
        $result = $this->serverOps->run(
            ['pg_lsclusters', '--no-header'],
            $this->context('detect_cluster'),
        );

        if ($result->failed()) {
            return null;
        }

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];

            if (count($columns) >= 2 && $columns[0] !== '' && $columns[1] !== '') {
                return $columns[0].'-'.$columns[1];
            }
        }

        return null;
    }

    /**
     * Does the cluster answer?
     *
     * `pg_isready` against the loopback, not the socket: that is the connection the panel uses.
     */
    private function isReady(): ServerOpsResult
    {
        return $this->serverOps->run(
            ['pg_isready', '--host=127.0.0.1', '--port='.$this->port()],
            $this->context('verify'),
            timeout: 30,
        );
    }

    /**
     * Create (or re-credential) the panel's own role.
     *
     * The stored username wins: this is re-runnable, and a fresh random name each time would mint a
     * new superuser per run. A re-run reuses the name and rotates only the password.
     *
     * @throws EngineInstallException
     */
    private function provisionPanelAccount(): void
    {
        $connection = DatabaseConnection::firstOrNew(['engine' => $this->engine()]);

        $username = $this->existingPanelUsername($connection) ?? $this->generateUsername();
        $password = DatabasePassword::generate();

        // `DO` because PostgreSQL has no `CREATE ROLE IF NOT EXISTS`; ignoring the error would hide
        // real failures that ON_ERROR_STOP exists to surface.
        $sql = <<<SQL
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = {$this->literal($username)}) THEN
                    EXECUTE format('CREATE ROLE %I LOGIN SUPERUSER', {$this->literal($username)});
                END IF;
            END
            \$\$;
            ALTER ROLE {$this->ident($username)} WITH LOGIN SUPERUSER PASSWORD {$this->literal($password)};
            SQL;

        $result = $this->psqlAsPostgres($sql);

        if ($result->failed()) {
            throw EngineInstallException::because('grant_failed', $result->reference);
        }

        $connection->fill([
            // TCP on the loopback, not the socket: a socket lands on `peer` auth and is refused for
            // the panel's Linux user, while the default pg_hba allows scram-sha-256 from 127.0.0.1.
            'connection_type' => 'tcp',
            'host' => '127.0.0.1',
            'port' => $this->port(),
            'socket' => config("server.databases.engines.{$this->engine()}.default_socket"),
            'username' => $username,
            // `encrypted` cast: a copy of the panel's database is useless without APP_KEY.
            'password' => $password,
        ])->save();
    }

    /**
     * Run SQL as the `postgres` superuser.
     *
     * `runuser -u postgres` because peer auth identifies the *OS* user (ServerOps is root, and
     * `psql -U postgres` over the socket is refused).
     *
     * Statements go over stdin, never argv: this creates a SUPERUSER credential and a command
     * line is readable in /proc. `ON_ERROR_STOP=1` because psql otherwise exits 0 on a script
     * whose statements failed.
     */
    private function psqlAsPostgres(string $sql): ServerOpsResult
    {
        return $this->serverOps->run(
            [
                'runuser', '-u', 'postgres', '--',
                $this->client(), '--dbname=postgres',
                '--set=ON_ERROR_STOP=1', '-q', '-t', '-A',
            ],
            $this->context('provision_account'),
            timeout: 60,
            input: $sql,
        );
    }

    /**
     * The role already provisioned for this engine, if any.
     *
     * `postgres` is never ours: it is the cluster's own superuser, which this class never touches.
     */
    private function existingPanelUsername(DatabaseConnection $connection): ?string
    {
        $stored = (string) ($connection->username ?? '');

        return str_starts_with($stored, self::USERNAME_PREFIX) ? $stored : null;
    }

    private function generateUsername(): string
    {
        return self::USERNAME_PREFIX.Str::lower(Str::random(self::USERNAME_RANDOM));
    }

    /**
     * @return array<int, string>
     */
    private function packages(): array
    {
        return (array) config('server.databases.postgresql.packages', ['postgresql']);
    }

    private function serverPackage(): string
    {
        return (string) config('server.databases.postgresql.server_package', 'postgresql');
    }

    private function client(): string
    {
        return (string) config("server.databases.engines.{$this->engine()}.client", 'psql');
    }

    private function port(): int
    {
        return (int) config("server.databases.engines.{$this->engine()}.default_port", 5432);
    }

    /**
     * @return array<string, string>
     */
    private function context(string $op): array
    {
        return ['feature' => 'database', 'engine' => $this->engine(), 'op' => $op];
    }

    /**
     * Maps apt's output to a stable code. Unmatched output is `unknown`, not a guess.
     */
    private function classify(string $stderr): string
    {
        foreach ((array) config('server.databases.failure_reasons', []) as $reason => $pattern) {
            if (preg_match($pattern, $stderr) === 1) {
                return (string) $reason;
            }
        }

        return 'unknown';
    }

    private function report(?callable $onStep, string $step): void
    {
        if ($onStep !== null) {
            $onStep($step);
        }
    }

    private function ident(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
