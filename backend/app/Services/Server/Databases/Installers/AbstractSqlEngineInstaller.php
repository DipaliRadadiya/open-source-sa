<?php

namespace App\Services\Server\Databases\Installers;

use App\Contracts\EngineInstaller;
use App\Exceptions\Server\Database\EngineInstallException;
use App\Models\DatabaseConnection;
use App\Services\Server\Databases\DatabasePassword;
use App\Services\Server\ServerOps;
use Illuminate\Support\Str;

/**
 * Shared install path for MySQL and MariaDB. They differ only in package names
 * and client binary — everything below is identical, which is the whole reason
 * the panel's own account is created over the unix socket rather than by setting
 * a root password: `ALTER USER root` syntax differs between the two, and
 * `CREATE USER` does not.
 *
 * The sequence, and why it is this order:
 *
 *  1. Refuse if the *other* SQL engine already owns the port. They are mutually
 *     exclusive on 3306; installing the second leaves it dead and the panel
 *     pointing at whichever won.
 *  2. apt-install the server package (skipped when already present, so a
 *     migrated server goes straight to step 3).
 *  3. Enable and start the service, then prove it answers. A package that
 *     unpacked is not an engine that runs.
 *  4. Create the panel's own account and store the credential.
 *
 * Nothing here touches root. See memory/database-engine-install-design.md for
 * why that is deliberate rather than lazy.
 */
abstract class AbstractSqlEngineInstaller implements EngineInstaller
{
    /**
     * `panel_` plus ten random characters — sixteen in total.
     *
     * Random so a fixed, guessable account name is not sitting behind port 3306
     * on every install of this panel. Prefixed anyway, because an admin reading
     * `SELECT user FROM mysql.user` has to be able to tell this is the panel's
     * account: an opaque name holding ALL PRIVILEGES looks exactly like a
     * backdoor, and someone would eventually delete it. The prefix is also the
     * only way to identify an orphan if the stored row is ever lost.
     *
     * Sixteen characters because **MySQL 5.7 capped usernames at 16** (8.0 raised
     * it to 32, MariaDB allows 80). An over-long name is silently truncated and
     * then fails authentication, which is a miserable thing to debug. The
     * username is not the secret, so there is nothing to buy with more length.
     */
    private const USERNAME_PREFIX = 'panel_';

    private const USERNAME_RANDOM = 10;

    public function __construct(protected ServerOps $serverOps) {}

    /** The apt packages that provide this engine's server. */
    abstract protected function packages(): array;

    /** The systemd unit name. */
    abstract protected function service(): string;

    /** The other SQL engine, which cannot coexist with this one. */
    abstract protected function conflictsWith(): string;

    public function installed(): bool
    {
        // dpkg-query rather than "does the binary exist": the client packages are
        // installed on plenty of boxes that do not run a server, and the panel
        // would then report an engine it cannot use.
        //
        // Matched with str_contains on trimmed output rather than compared for
        // equality. dpkg's status field is three words, but whether anything
        // surrounds them is not something to stake detection on — and a
        // mis-detection here either skips a needed install or reinstalls over a
        // live engine.
        $status = trim($this->serverOps->run(
            ['dpkg-query', '-W', '-f=${Status}', $this->packages()[0]],
            ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'detect'],
        )->output());

        return str_contains($status, 'install ok installed');
    }

    /**
     * @throws EngineInstallException
     */
    public function install(?callable $onStep = null, ?callable $onOutput = null): void
    {
        $this->report($onStep, 'checking_conflicts');
        $this->assertPortIsOurs();

        if (! $this->installed()) {
            $this->report($onStep, 'preparing');
            $result = $this->serverOps->run(
                array_merge(['apt-get', 'install', '-y', '--no-install-recommends'], $this->packages()),
                ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'install'],
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

        $this->startService($onStep);
        $this->report($onStep, 'creating_panel_account');
        $this->provisionPanelAccount();
    }

    /**
     * Refuse when the other SQL engine is already installed.
     *
     * Checked before apt rather than after: on Debian-family systems installing
     * mysql-server over mariadb-server (or the reverse) removes the first as a
     * conflicting package, which would take the existing databases' server with
     * it. That is not a thing to discover halfway through.
     *
     * @throws EngineInstallException
     */
    private function assertPortIsOurs(): void
    {
        $other = app(EngineInstallerManager::class)->installer($this->conflictsWith());

        if ($other->installed()) {
            throw EngineInstallException::portTaken($this->conflictsWith());
        }
    }

    /**
     * @throws EngineInstallException
     */
    private function startService(?callable $onStep): void
    {
        $this->report($onStep, 'starting_service');

        foreach ([['enable', '--now'], ['restart']] as $args) {
            $this->serverOps->run(
                array_merge(['systemctl'], $args, [$this->service()]),
                ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'service'],
                timeout: 120,
            );
        }

        // Proven, not assumed. A package that unpacked is not an engine that
        // answers, and every step after this one needs it to.
        $this->report($onStep, 'verifying_connection');
        $ping = $this->socketQuery('SELECT 1;');

        if ($ping->failed()) {
            throw EngineInstallException::rootUnreachable($ping->reference);
        }
    }

    /**
     * Create (or re-credential) the panel's own account.
     *
     * @throws EngineInstallException
     */
    private function provisionPanelAccount(): void
    {
        $connection = DatabaseConnection::firstOrNew(['engine' => $this->engine()]);

        // The stored username wins. A random name plus CREATE USER IF NOT EXISTS
        // would mint a *new* full-privilege account on every run, and both the
        // installer and this endpoint are re-runnable — so a re-run has to reuse
        // the name and rotate only the password.
        $username = $this->existingPanelUsername($connection) ?? $this->generateUsername();
        $password = DatabasePassword::generate();

        // Piped over stdin, never argv: anything on a command line is readable
        // in /proc for the lifetime of the process, and this is a credential with
        // GRANT OPTION.
        $sql = <<<SQL
            CREATE USER IF NOT EXISTS '{$username}'@'localhost' IDENTIFIED BY '{$this->escape($password)}';
            ALTER USER '{$username}'@'localhost' IDENTIFIED BY '{$this->escape($password)}';
            GRANT ALL PRIVILEGES ON *.* TO '{$username}'@'localhost' WITH GRANT OPTION;
            FLUSH PRIVILEGES;
            SQL;

        $result = $this->socketQuery($sql);

        if ($result->failed()) {
            throw EngineInstallException::because('grant_failed', $result->reference);
        }

        $connection->fill([
            'connection_type' => 'socket',
            'host' => '127.0.0.1',
            'port' => (int) config("server.databases.engines.{$this->engine()}.default_port"),
            'socket' => config("server.databases.engines.{$this->engine()}.default_socket"),
            'username' => $username,
            // `encrypted` cast — a copy of the panel's database on its own is
            // useless without APP_KEY, which is the realistic leak (backups,
            // snapshots, a file sent to support).
            'password' => $password,
        ])->save();
    }

    /**
     * The username already provisioned for this engine, if any.
     *
     * `root` is explicitly not ours: that is the seeded default from
     * `DatabaseManager::connection()`, and reusing it would mean granting and
     * re-crededentialing the root account — the exact thing this class exists to
     * avoid.
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
     * Runs SQL as the engine's root over the unix socket.
     *
     * No password anywhere: on MariaDB 10.4+ and MySQL 8 on Ubuntu, root@localhost
     * authenticates by *being OS root*, which ServerOps already is. That is what
     * makes one code path serve both engines.
     */
    private function socketQuery(string $sql)
    {
        return $this->serverOps->run(
            [$this->client(), '--protocol=socket', '--user=root'],
            ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'provision_account'],
            timeout: 60,
            input: $sql,
        );
    }

    private function client(): string
    {
        return (string) config("server.databases.engines.{$this->engine()}.client", 'mysql');
    }

    /**
     * Escapes a value for a single-quoted SQL literal.
     *
     * Generated passwords come from a fixed alphabet and contain none of these,
     * so this is belt-and-braces — but the cost of being wrong here is an
     * injected GRANT, so it is not left to the alphabet's good behaviour.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
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
}
