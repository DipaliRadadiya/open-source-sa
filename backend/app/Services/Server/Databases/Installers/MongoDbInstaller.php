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
 * Deliberately not extending {@see AbstractSqlEngineInstaller}. That class shares
 * a path between MySQL and MariaDB because they differ only in package and client
 * name; MongoDB differs in every step that matters — it is not in Ubuntu's
 * archive, it does not use SQL, it does not listen on 3306 so it conflicts with
 * nothing, and **it ships with authentication switched off**. Folding it in would
 * have meant four abstract methods and a branch in every one.
 *
 * Four things this has to get right, each of which is a way to leave a server
 * worse than it was found:
 *
 *  - **The repository is versioned and codename-specific.** MongoDB publishes
 *    per-series lists (`8.0`, `7.0`) per Ubuntu codename; there is no "latest"
 *    line. A wrong codename produces a list that adds cleanly and then carries
 *    nothing, so `apt-get install` fails with "Unable to locate package" — which
 *    reads as our bug, not a missing repository.
 *  - **`apt-get update` after adding it.** The list on disk is not the index.
 *  - **Auth is off by default, and turning it on edits somebody's config.** The
 *    package default binds to localhost with no authentication, which on a panel
 *    that gives every site its own Linux user means every site can read every
 *    other site's collections — the same isolation hole per-app FPM pools were
 *    built to close. So a *fresh* install gets authentication enabled. A server
 *    that already had MongoDB does not: its config is not ours to rewrite and
 *    something already connects to it. {@see memory/database-engine-install-design.md}
 *  - **The account is created before auth is enabled**, because after it there is
 *    only the localhost exception to get back in, and that is a narrower door
 *    than it looks.
 *
 * Nothing here stores a root password, for the same reason the SQL installer does
 * not: MongoDB has no root account to borrow, and inventing a superuser we then
 * have to keep is a secret that did not need to exist.
 */
class MongoDbInstaller implements EngineInstaller
{
    /**
     * Same shape and the same reasoning as the SQL installer's: an admin reading
     * `db.getUsers()` has to be able to tell which account is the panel's, or an
     * opaque name holding `root` looks exactly like a backdoor.
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
        // The server package, not `mongosh`. The shell is installed on plenty of
        // boxes that only *talk* to a remote MongoDB, and treating one of those
        // as "installed" would have the panel offer databases it cannot make.
        $status = trim($this->serverOps->run(
            ['dpkg-query', '-W', '-f=${Status}', $this->serverPackage()],
            ['feature' => 'database', 'engine' => 'mongodb', 'op' => 'detect'],
        )->output());

        return str_contains($status, 'install ok installed');
    }

    /**
     * @throws EngineInstallException
     */
    public function install(): void
    {
        // Whether *this run* put MongoDB on the box. It decides whether the
        // config is ours to change: a server that already had one is a server
        // something already connects to.
        $fresh = ! $this->installed();

        if ($fresh) {
            $this->addRepository();
            $this->installPackages();
        }

        $this->startService();
        $this->provisionPanelAccount($fresh);
    }

    /**
     * Add MongoDB's apt repository, signed-by rather than trusted globally.
     *
     * Idempotent: an existing list for the same series is left alone, so a
     * re-run after a failure does not stack duplicate sources.
     *
     * @throws EngineInstallException
     */
    private function addRepository(): void
    {
        $series = $this->series();
        // MongoDB publishes an ASCII-armoured key. APT chooses the keyring
        // parser from the extension: `.asc` is armoured, while `.gpg` must be
        // dearmoured binary data. The old installer saved the armoured response
        // as `.gpg`, so apt ignored it and reported NO_PUBKEY even though the
        // file existed.
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

        // The pair is the unit of idempotency. Checking only the list made a
        // failed first attempt permanent: the malformed key remained beside a
        // source file, and every retry skipped the code that could repair it.
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

        // --fail so an error page is never written out as a key, and https only
        // including across redirects.
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

        // The codename is the panel's own, from the OS it detected — not a
        // guess. A list naming a codename MongoDB does not publish for adds
        // without complaint and then carries no packages at all.
        $this->must($this->serverOps->run(
            ['tee', $list],
            $this->context('repo_list'),
            input: $line,
        ), 'repository_failed');

        // The list on disk is not the index. Without this, the very next
        // apt-get install fails with "Unable to locate package mongodb-org",
        // which reads as a broken panel rather than a stale index.
        $this->must($this->serverOps->run(
            ['apt-get', 'update'],
            $this->context('repo_update'),
            timeout: (int) config('server.databases.install_timeout', 900),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
        ), 'repository_failed');
    }

    /**
     * @throws EngineInstallException
     */
    private function installPackages(): void
    {
        $result = $this->serverOps->run(
            array_merge(['apt-get', 'install', '-y', '--no-install-recommends'], $this->packages()),
            $this->context('install'),
            timeout: (int) config('server.databases.install_timeout', 900),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
        );

        if ($result->failed()) {
            throw EngineInstallException::because(
                $this->classify($result->errorOutput()),
                $result->reference,
            );
        }
    }

    /**
     * @throws EngineInstallException
     */
    private function startService(): void
    {
        foreach ([['enable', '--now'], ['restart']] as $args) {
            $this->serverOps->run(
                array_merge(['systemctl'], $args, [$this->service()]),
                $this->context('service'),
                timeout: 120,
            );
        }

        // Proven, not assumed — a package that unpacked is not an engine that
        // answers, and every step after this needs it to. Held in a variable
        // rather than probed twice: the second call is a second round trip, and
        // if it happened to succeed the exception would carry a reference to a
        // command that worked.
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

        // Probed once. Each call is a round trip to the daemon, and the answer
        // cannot change underneath this method.
        $authOn = $this->authEnabled();

        if (! $fresh) {
            if ($authOn) {
                // Already installed, already locked down, and we have no
                // credential for it. Creating one would need a credential we do
                // not have, and guessing at the config of a server somebody
                // else set up is how you take their database down.
                if (blank($connection->username)) {
                    throw EngineInstallException::because('auth_required', null);
                }

                return;
            }

            // Pre-existing and open. Storing a username here would be actively
            // harmful, not merely useless: MongoEngine builds
            // `mongodb://user:pass@host` whenever one is set, and a server with
            // authentication off *rejects* credentials rather than ignoring
            // them — so every later operation would fail on a database that
            // works fine. Record how to reach it and nothing else.
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

        // The stored username wins, so a re-run rotates the password rather than
        // minting a second full-privilege account each time.
        $username = $this->existingPanelUsername($connection) ?? $this->generateUsername();
        $password = DatabasePassword::generate();

        // Credentials travel inside the script on stdin, never on argv — this
        // one holds `root` on every database.
        $script = 'const admin = db.getSiblingDB("admin");'."\n"
            .'const existing = admin.getUser('.$this->js($username).');'."\n"
            .'if (existing) {'."\n"
            .'  admin.updateUser('.$this->js($username).', { pwd: '.$this->js($password).', roles: [{ role: "root", db: "admin" }] });'."\n"
            .'} else {'."\n"
            .'  admin.createUser({ user: '.$this->js($username).', pwd: '.$this->js($password).', roles: [{ role: "root", db: "admin" }] });'."\n"
            .'}'."\n";

        // Unauthenticated: this is a server we just installed, so authentication
        // is still off — it is switched on below, once there is an account to
        // switch it on behind.
        $result = $this->runScript($script, authenticated: false);

        if ($result->failed()) {
            throw EngineInstallException::because('grant_failed', $result->reference);
        }

        // Only now. The account has to exist before the door closes, or the
        // only way back in is the localhost exception.
        $this->enableAuthentication();

        $connection->fill([
            'connection_type' => 'tcp',
            'host' => '127.0.0.1',
            'port' => (int) config('server.databases.engines.mongodb.default_port', 27017),
            'socket' => null,
            'username' => $username,
            // `encrypted` cast on the model — a copy of the panel's database is
            // useless without APP_KEY, which is the realistic leak.
            'password' => $password,
        ])->save();
    }

    /**
     * Switch authentication on and restart.
     *
     * `/etc/mongod.conf` is a single YAML file with no conf.d, so this is a
     * read-modify-write of somebody's config — done only on a server this run
     * installed, and marked so a re-run replaces its own block instead of
     * appending a second `security:` key, which would make the file invalid
     * YAML and stop mongod starting at all.
     *
     * @throws EngineInstallException
     */
    private function enableAuthentication(): void
    {
        $path = (string) config('server.databases.mongodb.config_file', '/etc/mongod.conf');
        $read = $this->serverOps->run(['cat', $path], $this->context('auth_read'));

        // Unreadable is not the same as empty. `tee -a` on a path we could not
        // read would create a file holding nothing but our security block —
        // no dbPath, no bindIp — and mongod would not start again.
        // Unreadable is not the same as empty. `tee -a` on a path we could not
        // read would create a file holding nothing but our security block —
        // no dbPath, no bindIp — and mongod would not start again.
        if ($read->failed()) {
            throw EngineInstallException::because('auth_failed', $read->reference);
        }

        $current = $read->output();

        if (str_contains($current, self::CONFIG_MARKER)) {
            return;
        }

        // Appended rather than spliced into an existing `security:` block. If the
        // package default ever grows one, two `security:` keys would be a YAML
        // error — so a file that already mentions it is left exactly as it is and
        // the operator is told, rather than edited into something that will not
        // start.
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
     * Probed rather than read out of the config file: the file is what someone
     * intended, the probe is what the daemon is doing. An unauthenticated command
     * that needs privileges succeeds when auth is off and fails when it is on.
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
     * `--nodb` plus an explicit `connect()` inside the script, matching
     * {@see MongoEngine}: it is the only way to
     * keep a URI with credentials in it off the command line.
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
     * The OS codename MongoDB publishes for, from the box rather than a guess.
     *
     * Falls back to the configured default when `/etc/os-release` cannot be read
     * — a wrong codename is a repository that carries nothing, so the fallback is
     * a value somebody chose rather than an empty string.
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
}
