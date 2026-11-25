<?php

namespace App\Services\Server\Databases\Installers;

use App\Contracts\EngineInstaller;
use App\Exceptions\Server\Database\EngineInstallException;
use App\Models\DatabaseConnection;
use App\Services\Server\Databases\DatabasePassword;
use App\Services\Server\Databases\SqlRootAccess;
use App\Services\Server\ServerOps;
use Illuminate\Support\Str;

/**
 * Shared install path for MySQL and MariaDB, which differ only in package names and client binary.
 * The panel's own account is created over the unix socket, not by setting a root password, because
 * `ALTER USER root` syntax differs between the two and `CREATE USER` does not.
 *
 * The sequence:
 *
 *  1. Refuse if the other SQL engine is installed: they are mutually exclusive on 3306.
 *  2. apt-install the server package (skipped when present, so a migrated server goes to step 3).
 *  3. Enable and start the service, then prove it answers.
 *  4. Create the panel's own account and store the credential.
 *
 * Nothing here touches root: its password is the user's to set (see ChangeDatabaseRootPassword).
 */
abstract class AbstractSqlEngineInstaller implements EngineInstaller
{
    /**
     * `panel_` plus ten random characters — sixteen in total.
     *
     * Random, so no guessable account name sits behind port 3306 on every install. Prefixed so an
     * admin reading `mysql.user` can tell it is the panel's (an opaque ALL PRIVILEGES account looks
     * like a backdoor) and can find an orphan if the stored row is lost.
     *
     * Sixteen characters because MySQL 5.7 capped usernames at 16: longer ones are silently
     * truncated and then fail authentication. The username is not the secret.
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

    /** Public for EngineInstallerManager::unavailableReason(). */
    public function conflictingEngine(): string
    {
        return $this->conflictsWith();
    }

    public function installed(): bool
    {
        // dpkg-query, not "does the binary exist": client packages are installed on boxes that run
        // no server. `str_contains` rather than equality, since a mis-detection either skips a
        // needed install or reinstalls over a live engine.
        $status = trim($this->serverOps->run(
            ['dpkg-query', '-W', '-f=${Status}', $this->packages()[0]],
            ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'detect'],
            // Exit 1: not installed, an answer rather than a failure (else it floods the Error Log).
            expectedExitCodes: [1],
        )->output());

        return str_contains($status, 'install ok installed');
    }

    /**
     * @throws EngineInstallException
     */
    public function install(?callable $onStep = null, ?callable $onOutput = null, ?bool $wasAbsent = null): void
    {
        $this->report($onStep, 'checking_conflicts');
        $this->assertPortIsOurs();

        if (! $this->installed()) {
            $this->report($onStep, 'preparing');
            $result = $this->serverOps->apt(
                array_merge(['apt-get', 'install', '-y', '--no-install-recommends'], $this->packages()),
                ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'install'],
                timeout: (int) config('server.databases.install_timeout', 900),
                env: ['DEBIAN_FRONTEND' => 'noninteractive'],
                onOutput: $onOutput,
                // A fresh box's unattended-upgrades holds the lock for minutes; say so.
                onWait: fn () => $this->report($onStep, 'waiting_for_package_manager'),
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
     * Checked before apt: on Debian-family systems installing mysql-server over mariadb-server
     * (or the reverse) removes the first as a conflicting package, taking existing databases with it.
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

        // A package that unpacked is not an engine that answers; later steps need it to.
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

        // The stored username wins: a random name plus CREATE USER IF NOT EXISTS would mint a new
        // full-privilege account on every re-run, so a re-run reuses the name and rotates the password.
        $username = $this->existingPanelUsername($connection) ?? $this->generateUsername();
        $password = DatabasePassword::generate();

        // Piped over stdin, never argv: a command line is readable in /proc, and this credential
        // has GRANT OPTION.
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
            // `encrypted` cast: a copy of the database alone is useless without APP_KEY.
            'password' => $password,
        ])->save();
    }

    /**
     * The username already provisioned for this engine, if any.
     *
     * `root` is explicitly not ours: it is the seeded default from `DatabaseManager::connection()`,
     * and reusing it would re-credential the root account, which this class exists to avoid.
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
     * No password first: on MariaDB 10.4+ and MySQL 8 on Ubuntu, root@localhost authenticates by
     * being OS root, which ServerOps already is. Then the root password the user set or saved, if
     * any: MySQL loses socket login once root has a password (see SqlRootAccess).
     */
    private function socketQuery(string $sql)
    {
        return (new SqlRootAccess($this->serverOps))->run($this->engine(), $sql, 'provision_account');
    }

    /**
     * Escapes a value for a single-quoted SQL literal.
     *
     * Generated passwords contain none of these characters; this is belt-and-braces, since the
     * cost of being wrong is an injected GRANT.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Maps apt's output to a stable code; unmatched output is `unknown`, not a guess.
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
