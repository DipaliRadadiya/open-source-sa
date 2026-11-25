<?php

namespace App\Services\Server\Databases\Installers;

use App\Contracts\EngineInstaller;
use App\Exceptions\Server\Database\EngineInstallException;
use App\Models\DatabaseConnection;
use App\Services\Server\Databases\DatabasePassword;
use App\Services\Server\Databases\MongoEngine;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

/**
 * MongoDB.
 *
 * Deliberately not extending {@see AbstractSqlEngineInstaller}: MongoDB differs in every step
 * that matters (not in Ubuntu's archive, no SQL, not on 3306, and **it ships with
 * authentication switched off**).
 *
 * Four things this has to get right:
 *
 *  - **The repository is versioned and codename-specific** (per-series lists such as `8.0`,
 *    per Ubuntu codename; no "latest"). A wrong codename adds cleanly but carries nothing,
 *    so `apt-get install` fails with "Unable to locate package".
 *  - **`apt-get update` after adding it.** The list on disk is not the index.
 *  - **Auth is off by default, and turning it on edits somebody's config.** With no auth every
 *    site's Linux user can read every other site's collections. A *fresh* install gets
 *    authentication enabled; a server that already had MongoDB keeps its config, which is not
 *    ours to rewrite. {@see memory/database-engine-install-design.md}
 *  - **The account is created before auth is enabled**: afterwards only the narrow localhost
 *    exception gets you back in.
 *
 * No root password is stored, as in the SQL installer: MongoDB has no root account to borrow.
 */
class MongoDbInstaller implements EngineInstaller
{
    /**
     * As in the SQL installer: an admin reading `db.getUsers()` must be able to tell which
     * account is the panel's, or an opaque name holding `root` looks like a backdoor.
     */
    private const USERNAME_PREFIX = 'panel_';

    private const USERNAME_RANDOM = 10;

    /** Marks the block we wrote, so a re-run edits it instead of appending a second one. */
    private const CONFIG_MARKER = '# Managed by the control panel.';

    public function __construct(private ServerOps $serverOps) {}

    public function engine(): string
    {
        return 'mongodb';
    }

    public function installed(): bool
    {
        // The server package, not `mongosh`: the shell is often installed only to talk to a remote MongoDB.
        $status = trim($this->serverOps->run(
            ['dpkg-query', '-W', '-f=${Status}', $this->serverPackage()],
            ['feature' => 'database', 'engine' => 'mongodb', 'op' => 'detect'],
            // Exit 1: not installed, an answer.
            expectedExitCodes: [1],
        )->output());

        return str_contains($status, 'install ok installed');
    }

    /**
     * @throws EngineInstallException
     */
    public function install(?callable $onStep = null, ?callable $onOutput = null, ?bool $wasAbsent = null): void
    {
        // Whether this *install* (not this attempt) put MongoDB on the box; it decides whether the
        // config is ours to change. Taken from what was recorded when the install was requested:
        // a failed first attempt leaves the package present, and asking the box again would take
        // the brownfield path and skip enabling authentication. Null: nobody recorded an answer.
        $fresh = $wasAbsent ?? ! $this->installed();

        if ($fresh) {
            $this->report($onStep, 'preparing_repository');
            $this->addRepository($onStep);
            $this->report($onStep, 'preparing');
            $this->installPackages($onOutput);
        }

        $this->startService($onStep);
        $this->report($onStep, 'creating_panel_account');
        $this->provisionPanelAccount($fresh);
    }

    /**
     * Add MongoDB's apt repository, signed-by rather than trusted globally.
     *
     * Idempotent: an existing list for the same series is left alone, so a re-run does not
     * stack duplicate sources.
     *
     * @throws EngineInstallException
     */
    private function addRepository(?callable $onStep): void
    {
        $series = $this->series();
        // MongoDB's key is ASCII-armoured. APT picks the parser from the extension: `.asc` is
        // armoured, `.gpg` must be binary (an armoured `.gpg` gives NO_PUBKEY).
        $keyring = "/etc/apt/keyrings/mongodb-server-{$series}.asc";
        $list = "/etc/apt/sources.list.d/mongodb-org-{$series}.list";

        $line = sprintf(
            "deb [ arch=%s signed-by=%s ] %s %s/mongodb-org/%s %s\n",
            $this->architecture(),
            $keyring,
            (string) config('server.databases.mongodb.repository_url'),
            $this->codename(),
            $series,
            (string) config('server.databases.mongodb.component', 'multiverse'),
        );

        // The list and key pair is the unit of idempotency; checking only the list would leave a
        // malformed key unrepaired on every retry.
        $existingList = $this->serverOps->run(['cat', $list], $this->context('repo_check_list'));
        $existingKey = $this->serverOps->run(['cat', $keyring], $this->context('repo_check_key'));

        if ($existingList->ok
            && trim($existingList->output()) === trim($line)
            && $existingKey->ok
            && str_contains($existingKey->output(), '-----BEGIN PGP PUBLIC KEY BLOCK-----')) {
            return;
        }

        $this->must($this->serverOps->run(
            ['install', '-d', '-m', '0755', '/etc/apt/keyrings'],
            $this->context('repo_keyring_dir'),
        ), 'repository_failed');

        // --fail so an error page is never written out as a key; https only, redirects included.
        $this->must($this->serverOps->run(
            [
                'curl', '--fail', '--location', '--silent', '--show-error',
                '--proto', '=https', '--proto-redir', '=https',
                '--output', $keyring,
                (string) config('server.databases.mongodb.key_url'),
            ],
            $this->context('repo_key'),
            timeout: 120,
        ), 'repository_failed');

        $downloadedKey = $this->serverOps->run(['cat', $keyring], $this->context('repo_key_verify'));

        if ($downloadedKey->failed()
            || ! str_contains($downloadedKey->output(), '-----BEGIN PGP PUBLIC KEY BLOCK-----')) {
            throw EngineInstallException::because('repository_failed', $downloadedKey->reference);
        }

        $this->must($this->serverOps->run(['chmod', '0644', $keyring], $this->context('repo_key_mode')), 'repository_failed');

        // The codename comes from the detected OS, not a guess: an unpublished one adds without
        // complaint and carries no packages.
        $this->must($this->serverOps->run(
            ['tee', $list],
            $this->context('repo_list'),
            input: $line,
        ), 'repository_failed');

        // The list on disk is not the index: without this, install fails with "Unable to locate package".
        $this->report($onStep, 'updating_package_index');

        $updated = $this->serverOps->apt(
            ['apt-get', 'update'],
            $this->context('repo_update'),
            timeout: (int) config('server.databases.install_timeout', 900),
            onWait: fn () => $this->report($onStep, 'waiting_for_package_manager'),
        );

        if ($updated->failed()) {
            // Classified, not assumed: a fresh server running unattended-upgrades holds the apt
            // lists lock, which is not a network problem. `repository_failed` stays the fallback.
            throw EngineInstallException::because(
                $this->classify($updated->errorOutput().' '.$updated->output(), 'repository_failed'),
                $updated->reference,
            );
        }
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
            onOutput: $onOutput,
        );

        if ($result->failed()) {
            // Both streams: apt splits its errors between them inconsistently,
            // and "No space left on device" in particular arrives on stdout.
            $reason = $this->classify($result->errorOutput().' '.$result->output());

            /*
             * "Unable to locate package" means something specific here: the panel wrote the
             * source list itself and `addRepository()` already ran `apt-get update` against it,
             * so the index was fetched and the package is not in it. MongoDB has not built for
             * this Ubuntu codename (26.04 ships the tools, not the server). Name the release
             * rather than send anyone to audit correct apt sources.
             */
            if ($reason === 'package_not_found') {
                $reason = 'os_unsupported';
            }

            throw EngineInstallException::because($reason, $result->reference);
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
                $this->context('service'),
                timeout: 120,
            );
        }

        // Proven, not assumed: an unpacked package is not an engine that answers. Probed once so
        // the exception's reference is of the failing command.
        $this->report($onStep, 'verifying_connection');
        $ping = $this->ping();

        if ($ping->failed()) {
            throw EngineInstallException::because('unreachable', $ping->reference);
        }
    }

    /**
     * Create (or re-credential) the panel's own account, and on a fresh install
     * turn authentication on behind it.
     *
     * @throws EngineInstallException
     */
    private function provisionPanelAccount(bool $fresh): void
    {
        $connection = DatabaseConnection::firstOrNew(['engine' => 'mongodb']);

        // Probed once: each call is a round trip to the daemon.
        $authOn = $this->authEnabled();

        if (! $fresh) {
            if ($authOn) {
                // Already locked down and we have no credential; never guess at somebody else's config.
                if (blank($connection->username)) {
                    throw EngineInstallException::because('auth_required', null);
                }

                return;
            }

            // Pre-existing and open. Never store a username: MongoEngine then builds
            // `mongodb://user:pass@host`, and a server with auth off *rejects* credentials.
            $connection->fill([
                'connection_type' => 'tcp',
                'host' => $connection->host ?: '127.0.0.1',
                'port' => (int) ($connection->port ?: config('server.databases.engines.mongodb.default_port', 27017)),
                'socket' => null,
                'username' => null,
                'password' => null,
            ])->save();

            return;
        }

        // The stored username wins, so a re-run rotates the password instead of minting another account.
        $username = $this->existingPanelUsername($connection) ?? $this->generateUsername();
        $password = DatabasePassword::generate();

        // Credentials travel in the script on stdin, never on argv: this account holds `root`.
        $script = 'const admin = db.getSiblingDB("admin");'."\n"
            .'const existing = admin.getUser('.$this->js($username).');'."\n"
            .'if (existing) {'."\n"
            .'  admin.updateUser('.$this->js($username).', { pwd: '.$this->js($password).', roles: [{ role: "root", db: "admin" }] });'."\n"
            .'} else {'."\n"
            .'  admin.createUser({ user: '.$this->js($username).', pwd: '.$this->js($password).', roles: [{ role: "root", db: "admin" }] });'."\n"
            .'}'."\n";

        // Unauthenticated: auth on a fresh install is only switched on below.
        $result = $this->runScript($script, authenticated: false);

        if ($result->failed()) {
            throw EngineInstallException::because('grant_failed', $result->reference);
        }

        // Only now: the account must exist before the door closes.
        $this->enableAuthentication();

        $connection->fill([
            'connection_type' => 'tcp',
            'host' => '127.0.0.1',
            'port' => (int) config('server.databases.engines.mongodb.default_port', 27017),
            'socket' => null,
            'username' => $username,
            // `encrypted` cast on the model: a copy of the panel's database is useless without APP_KEY.
            'password' => $password,
        ])->save();
    }

    /**
     * Switch authentication on and restart.
     *
     * `/etc/mongod.conf` is a single YAML file with no conf.d, so this edits somebody's config:
     * only on a server this run installed, and marked so a re-run does not append a second
     * `security:` key (invalid YAML, mongod would not start).
     *
     * @throws EngineInstallException
     */
    private function enableAuthentication(): void
    {
        $path = (string) config('server.databases.mongodb.config_file', '/etc/mongod.conf');
        $read = $this->serverOps->run(['cat', $path], $this->context('auth_read'));

        // Unreadable is not empty: `tee -a` on it would create a file holding only our security
        // block (no dbPath, no bindIp) and mongod would not start.
        if ($read->failed()) {
            throw EngineInstallException::because('auth_failed', $read->reference);
        }

        $current = $read->output();

        if (str_contains($current, self::CONFIG_MARKER)) {
            return;
        }

        // Appended, never spliced into an existing `security:` block: two keys are a YAML error, so
        // a file that already has one is left as it is and the operator is told.
        if (preg_match('/^\s*security:/m', $current) === 1) {
            throw EngineInstallException::because('auth_config_present', null);
        }

        $block = "\n".self::CONFIG_MARKER."\n"
            ."# Authentication is off in the package default, which on a server\n"
            ."# hosting several sites means every site can read every other\n"
            ."# site's data. Remove this block to go back to that.\n"
            ."security:\n  authorization: enabled\n";

        $this->must(
            $this->serverOps->run(['tee', '-a', $path], $this->context('auth_write'), input: $block),
            'auth_failed',
        );

        $this->must(
            $this->serverOps->run(['systemctl', 'restart', $this->service()], $this->context('auth_restart'), timeout: 120),
            'auth_failed',
        );
    }

    /**
     * Whether the running server demands credentials.
     *
     * Probed, not read from the config file: the probe is what the daemon is doing. A privileged
     * unauthenticated command succeeds when auth is off and fails when it is on.
     */
    private function authEnabled(): bool
    {
        return $this->runScript('db.getSiblingDB("admin").getUsers();', authenticated: false)->failed();
    }

    private function ping(): ServerOpsResult
    {
        return $this->runScript('db.getSiblingDB("admin").runCommand({ ping: 1 });', authenticated: false);
    }

    /**
     * Run a mongosh script over stdin.
     *
     * `--nodb` plus an explicit `connect()` inside the script, as in {@see MongoEngine}: the only
     * way to keep a URI with credentials off the command line.
     */
    private function runScript(string $body, bool $authenticated): ServerOpsResult
    {
        $connection = DatabaseConnection::firstOrNew(['engine' => 'mongodb']);

        $host = $connection->host ?: '127.0.0.1';
        $port = (int) ($connection->port ?: config('server.databases.engines.mongodb.default_port', 27017));

        $credentials = '';

        if ($authenticated && filled($connection->username)) {
            $credentials = rawurlencode((string) $connection->username)
                .':'.rawurlencode((string) $connection->password).'@';
        }

        $uri = "mongodb://{$credentials}{$host}:{$port}/admin?authSource=admin";

        return $this->serverOps->run(
            [$this->client(), '--quiet', '--nodb'],
            $this->context('provision_account'),
            timeout: 60,
            input: 'const db = connect('.$this->js($uri).');'."\n".$body."\n",
        );
    }

    private function existingPanelUsername(DatabaseConnection $connection): ?string
    {
        $stored = (string) ($connection->username ?? '');

        return str_starts_with($stored, self::USERNAME_PREFIX) ? $stored : null;
    }

    private function generateUsername(): string
    {
        return self::USERNAME_PREFIX.Str::lower(Str::random(self::USERNAME_RANDOM));
    }

    /** @return array<int, string> */
    private function packages(): array
    {
        return (array) config('server.databases.mongodb.packages', ['mongodb-org']);
    }

    private function serverPackage(): string
    {
        return (string) config('server.databases.mongodb.server_package', 'mongodb-org-server');
    }

    private function service(): string
    {
        return (string) config('server.databases.mongodb.service', 'mongod');
    }

    private function series(): string
    {
        return (string) config('server.databases.mongodb.series', '8.0');
    }

    private function client(): string
    {
        return (string) config('server.databases.engines.mongodb.client', 'mongosh');
    }

    /**
     * The OS codename MongoDB publishes for, from the box rather than a guess. Falls back to the
     * configured default when `/etc/os-release` cannot be read.
     */
    private function codename(): string
    {
        $configured = (string) config('server.databases.mongodb.codename', '');

        if ($configured !== '') {
            return $configured;
        }

        $release = $this->serverOps->run(
            ['cat', (string) config('server.os_release', '/etc/os-release')],
            $this->context('codename'),
        );

        if ($release->ok && preg_match('/^VERSION_CODENAME=(\S+)/m', $release->output(), $m) === 1) {
            return trim($m[1], "\"'");
        }

        return (string) config('server.databases.mongodb.codename_fallback', 'noble');
    }

    private function architecture(): string
    {
        return (string) config('server.databases.mongodb.architecture', 'amd64');
    }

    /** @return array<string, mixed> */
    private function context(string $op): array
    {
        return ['feature' => 'database', 'engine' => 'mongodb', 'op' => $op];
    }

    /** Safe JS string literal for any interpolated value. */
    private function js(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws EngineInstallException
     */
    private function must(ServerOpsResult $result, string $reason): void
    {
        if ($result->failed()) {
            throw EngineInstallException::because($reason, $result->reference);
        }
    }

    private function report(?callable $onStep, string $step): void
    {
        if ($onStep !== null) {
            $onStep($step);
        }
    }

    /**
     * Maps apt's output to a stable code. Unmatched output is `unknown`, not a guess.
     */
    private function classify(string $stderr, string $fallback = 'unknown'): string
    {
        foreach ((array) config('server.databases.failure_reasons', []) as $reason => $pattern) {
            if (preg_match($pattern, $stderr) === 1) {
                return (string) $reason;
            }
        }

        return $fallback;
    }
}
