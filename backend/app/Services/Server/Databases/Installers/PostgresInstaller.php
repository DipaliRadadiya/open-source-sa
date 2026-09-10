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
 * Standalone rather than an `AbstractSqlEngineInstaller` subclass, for two
 * reasons that are not stylistic:
 *
 *  - That class reaches the engine with `--protocol=socket --user=root`, which
 *    works because on MySQL and MariaDB `root@localhost` authenticates by
 *    *being OS root* — which ServerOps already is. PostgreSQL uses **peer**
 *    auth, and root is not `postgres`, so the same call is refused. Everything
 *    here goes through `runuser -u postgres`.
 *  - It refuses to install when the *other* SQL engine is present, because they
 *    fight over 3306. PostgreSQL is on 5432 and coexists with both, so that
 *    check would be inventing a conflict.
 *
 * **Health is never asked of systemd**, and that is measured rather than
 * assumed. The shipped units say:
 *
 *     # postgresql.service
 *     Type=oneshot
 *     ExecStart=/bin/true
 *     RemainAfterExit=on
 *
 * — its own comment calls it "more like a systemd target", so `is-active`
 * answers `active` because `/bin/true` succeeded, whatever the clusters are
 * doing. And the real unit is worse:
 *
 *     # postgresql@.service
 *     ExecStart=-/usr/bin/pg_ctlcluster --skip-systemctl-redirect %i start
 *
 * The leading `-` tells systemd to ignore a non-zero exit, so even starting a
 * specific cluster reports success when the cluster failed to come up. There is
 * no systemd question that answers "is PostgreSQL up". `pg_isready` is.
 *
 * Nothing here touches `postgresql.conf` or `pg_hba.conf`. Ubuntu's default
 * hba already allows scram-sha-256 from 127.0.0.1, which is exactly what the
 * stored connection uses — so the panel needs no edit to a file that other
 * things on the box may depend on.
 */
class PostgresInstaller implements EngineInstaller
{
    /**
     * `panel_` plus ten random characters.
     *
     * Random so a fixed, guessable superuser is not sitting behind 5432 on
     * every install of this panel; prefixed anyway, because an admin reading
     * `\du` has to be able to tell this is the panel's role — an opaque name
     * holding SUPERUSER looks exactly like a backdoor, and someone would
     * eventually drop it.
     *
     * No length ceiling worth worrying about here, unlike MySQL's 16/32: a
     * PostgreSQL identifier allows 63 bytes.
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
     * `dpkg-query` on the server package rather than "does psql exist", for the
     * reason the MongoDB installer gives: the client packages are on plenty of
     * boxes that run no server, and reporting one of those as installed offers
     * databases the panel cannot make.
     */
    public function installed(): bool
    {
        $status = trim($this->serverOps->run(
            ['dpkg-query', '-W', '-f=${Status}', $this->serverPackage()],
            $this->context('detect'),
        )->output());

        return str_contains($status, 'install ok installed');
    }

    /**
     * @throws EngineInstallException
     */
    public function install(?callable $onStep = null, ?callable $onOutput = null, ?bool $wasAbsent = null): void
    {
        // Whether this install put PostgreSQL here, not whether this attempt
        // did. Same reasoning as MongoDB's, and the same trap: a first attempt
        // that installed the package and then failed leaves it present, so a
        // retry that re-asks the box is told "already here" and takes the
        // brownfield path — on a server the panel then reports as installed.
        $fresh = $wasAbsent ?? ! $this->installed();

        if ($fresh) {
            $this->report($onStep, 'preparing');
            $this->installPackages($onOutput);
        }

        $this->startCluster($onStep);
        $this->report($onStep, 'creating_panel_account');
        $this->provisionPanelAccount();
    }

    /**
     * @throws EngineInstallException
     */
    private function installPackages(?callable $onOutput): void
    {
        $result = $this->serverOps->apt(
            array_merge(['apt-get', 'install', '-y', '--no-install-recommends'], $this->packages()),
            $this->context('install'),
            timeout: (int) config('server.databases.install_timeout', 900),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
            onOutput: $onOutput,
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
     * The cluster is **discovered**, not assumed to be `16-main`: the instance
     * name is version-and-name, the version moves with the distribution, and a
     * box can carry several. Guessing it would mean starting nothing on a
     * server whose cluster is called something else, and then reporting
     * success because systemd said so.
     *
     * @throws EngineInstallException
     */
    private function startCluster(?callable $onStep): void
    {
        $this->report($onStep, 'starting_service');

        $cluster = $this->cluster();

        if ($cluster === null) {
            // The package is installed and created no cluster — a real state
            // (a failed initdb, a purged cluster left behind) and one worth
            // naming, because "start it again" is not the fix.
            throw EngineInstallException::because('cluster_missing', null);
        }

        foreach ([['enable', '--now'], ['restart']] as $args) {
            $this->serverOps->run(
                array_merge(['systemctl'], $args, ["postgresql@{$cluster}"]),
                $this->context('service'),
                timeout: 120,
            );
        }

        // The whole reason this method does not trust its own systemctl calls.
        // See the class docblock: neither unit can report a failed cluster.
        $this->report($onStep, 'verifying_cluster');
        $ready = $this->isReady();

        if ($ready->failed()) {
            throw EngineInstallException::because('unreachable', $ready->reference);
        }
    }

    /**
     * The first cluster `pg_lsclusters` reports, as `<version>-<name>`.
     *
     * Parsed from its machine-readable listing: `--no-header` so there is no
     * header row to skip, and the first two columns are version and name.
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
     * `pg_isready` against the loopback rather than the socket, because that is
     * the connection the panel will actually use — a socket that answers while
     * the TCP listener is off would be a green check on a path nothing takes.
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
     * The stored username wins, for the reason the MySQL installer gives: a
     * random name plus `CREATE ROLE IF NOT EXISTS` would mint a *new*
     * superuser on every run, and both this and the endpoint that calls it are
     * re-runnable — so a re-run reuses the name and rotates only the password.
     *
     * @throws EngineInstallException
     */
    private function provisionPanelAccount(): void
    {
        $connection = DatabaseConnection::firstOrNew(['engine' => $this->engine()]);

        $username = $this->existingPanelUsername($connection) ?? $this->generateUsername();
        $password = DatabasePassword::generate();

        // `DO` because PostgreSQL has no `CREATE ROLE IF NOT EXISTS`, and the
        // alternative — create, ignore the error — is exactly the "a failure
        // that looks like success" shape ON_ERROR_STOP exists to prevent.
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
            // TCP on the loopback, not the socket. A socket connection lands on
            // `peer` auth and would be refused for the panel's Linux user,
            // whereas Ubuntu's default pg_hba already allows scram-sha-256 from
            // 127.0.0.1 — so this works without editing a file other things on
            // the box may depend on.
            'connection_type' => 'tcp',
            'host' => '127.0.0.1',
            'port' => $this->port(),
            'socket' => config("server.databases.engines.{$this->engine()}.default_socket"),
            'username' => $username,
            // `encrypted` cast — a copy of the panel's database on its own is
            // useless without APP_KEY, which is the realistic leak.
            'password' => $password,
        ])->save();
    }

    /**
     * Run SQL as the `postgres` superuser.
     *
     * `runuser -u postgres` because peer authentication identifies the *OS*
     * user: ServerOps is root, root is not postgres, and `psql -U postgres`
     * over the socket is refused. This is the one place the installer has
     * privileges the engine class never gets.
     *
     * Statements over stdin, never argv — this creates a SUPERUSER credential,
     * and anything on a command line is readable in /proc for the life of the
     * process. `ON_ERROR_STOP=1` because psql otherwise exits 0 on a script
     * whose statements failed, measured on PostgreSQL 16.
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
     * `postgres` is explicitly not ours: that is the cluster's own superuser,
     * and reusing it would mean re-credentialling the account this class
     * deliberately never touches.
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
     * Maps apt's output to a stable code. Unmatched output is `unknown` rather
     * than a guess — a wrong reason sends the user somewhere useless.
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
