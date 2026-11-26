<?php

use App\Exceptions\Server\Database\EngineInstallException;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Server\Databases\Installers\EngineInstallerManager;
use App\Services\Server\Databases\Installers\MongoDbInstaller;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * MongoDB is not in Ubuntu's archive and ships with authentication off, so its installer must
 * add a repository and close the open door the package leaves.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();
});

function mongoInstaller()
{
    return app(EngineInstallerManager::class)->installer('mongodb');
}

/**
 * @param  string  $installedPackage  package dpkg-query should report as present
 * @param  bool  $authOn  whether an unauthenticated privileged command fails
 * @param  string  $configFile  what `cat /etc/mongod.conf` returns
 */
function fakeMongoBox(array &$seen, string $installedPackage = '', bool $authOn = false, string $configFile = "storage:\n  dbPath: /var/lib/mongodb\n"): void
{
    Process::fake(function ($process) use (&$seen, $installedPackage, $authOn, $configFile) {
        $seen[] = $process;
        $command = $process->command;
        $binary = $command[0] ?? '';

        if ($binary === 'dpkg-query') {
            $asked = end($command);

            return Process::result(output: $asked === $installedPackage ? 'install ok installed' : 'unknown ok not-installed');
        }

        if ($binary === 'cat' && str_contains((string) ($command[1] ?? ''), 'mongod.conf')) {
            return Process::result(output: $configFile);
        }

        if ($binary === 'cat' && str_contains((string) ($command[1] ?? ''), 'os-release')) {
            return Process::result(output: "ID=ubuntu\nVERSION_CODENAME=jammy\n");
        }

        if ($binary === 'cat' && str_contains((string) ($command[1] ?? ''), 'mongodb-server-8.0.asc')) {
            return Process::result(output: "-----BEGIN PGP PUBLIC KEY BLOCK-----\nkey\n");
        }

        // `test -f` on the sources list: absent, so the repository gets added.
        if ($binary === 'test') {
            return Process::result(exitCode: 1);
        }

        if ($binary === 'mongosh') {
            $script = (string) $process->input;
            $unauthenticated = ! str_contains($script, '@127.0.0.1');

            // With auth on, an unauthenticated privileged command is refused: the installer's probe.
            if ($authOn && $unauthenticated && str_contains($script, 'getUsers')) {
                return Process::result(errorOutput: 'MongoServerError: command usersInfo requires authentication', exitCode: 1);
            }

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });
}

describe('a fresh install', function () {
    it('adds the repository and refreshes the index before installing', function () {
        $seen = [];
        fakeMongoBox($seen);

        mongoInstaller()->install();

        $order = collect($seen)->map(fn ($p) => implode(' ', $p->command))->values();

        $list = $order->search(fn (string $c) => str_starts_with($c, 'tee /etc/apt/sources.list.d/mongodb'));
        $update = $order->search(fn (string $c) => $c === implode(' ', aptCommand('update')));
        $install = $order->search(fn (string $c) => str_starts_with($c, 'apt-get install'));

        // The list on disk is not the index: without the update, apt fails with
        // "Unable to locate package mongodb-org".
        expect($list)->not->toBeFalse()
            ->and($update)->not->toBeFalse()
            ->and($install)->not->toBeFalse()
            ->and($list)->toBeLessThan($update)
            ->and($update)->toBeLessThan($install);
    });

    it('reports repository, package and service stages in their real order', function () {
        $seen = [];
        $steps = [];
        fakeMongoBox($seen);

        mongoInstaller()->install(function (string $step) use (&$steps) {
            $steps[] = $step;
        });

        expect($steps)->toBe([
            'preparing_repository',
            'updating_package_index',
            'preparing',
            'starting_service',
            'verifying_connection',
            'creating_panel_account',
        ]);
    });

    it('signs the repository with its own keyring, never the global trust store', function () {
        $seen = [];
        fakeMongoBox($seen);

        mongoInstaller()->install();

        $line = collect($seen)->first(fn ($p) => ($p->command[0] ?? '') === 'tee'
            && str_contains((string) ($p->command[1] ?? ''), 'sources.list.d'));

        // A key in the global store signs for every repository. The key is armoured, so it must
        // be `.asc`: named `.gpg`, apt parses it as a binary keyring and reports NO_PUBKEY.
        expect($line->input)->toContain('signed-by=/etc/apt/keyrings/')
            ->and($line->input)->toContain('mongodb-server-8.0.asc')
            ->and($line->input)->not->toContain('mongodb-server-8.0.gpg')
            ->and($line->input)->toContain('repo.mongodb.org');
    });

    it('repairs the old armoured key that was incorrectly named as a binary keyring', function () {
        $seen = [];

        Process::fake(function ($process) use (&$seen) {
            $seen[] = $process;
            $command = $process->command;
            $path = (string) ($command[1] ?? '');

            if (($command[0] ?? '') === 'dpkg-query') {
                return Process::result(output: 'unknown ok not-installed');
            }

            if (($command[0] ?? '') === 'cat' && str_contains($path, 'os-release')) {
                return Process::result(output: "ID=ubuntu\nVERSION_CODENAME=noble\n");
            }

            if (($command[0] ?? '') === 'cat' && str_contains($path, 'mongodb-org-8.0.list')) {
                return Process::result(output: "deb [ arch=amd64 signed-by=/etc/apt/keyrings/mongodb-server-8.0.gpg ] https://repo.mongodb.org/apt/ubuntu noble/mongodb-org/8.0 multiverse\n");
            }

            if (($command[0] ?? '') === 'cat' && str_contains($path, 'mongodb-server-8.0.asc')) {
                $downloaded = collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'curl');

                return $downloaded
                    ? Process::result(output: "-----BEGIN PGP PUBLIC KEY BLOCK-----\nkey\n")
                    : Process::result(errorOutput: 'missing', exitCode: 1);
            }

            return Process::result(exitCode: 0);
        });

        mongoInstaller()->install();

        $download = collect($seen)->first(fn ($p) => ($p->command[0] ?? '') === 'curl');
        $write = collect($seen)->first(fn ($p) => ($p->command[0] ?? '') === 'tee'
            && str_contains((string) ($p->command[1] ?? ''), 'mongodb-org-8.0.list'));

        expect($download)->not->toBeNull()
            ->and($download->command)->toContain('/etc/apt/keyrings/mongodb-server-8.0.asc')
            ->and($write->input)->toContain('signed-by=/etc/apt/keyrings/mongodb-server-8.0.asc')
            ->and(collect($seen)->contains(fn ($p) => $p->command === aptCommand('update')))->toBeTrue();
    });

    it('restores a missing key even when the repository list is already correct', function () {
        $seen = [];

        Process::fake(function ($process) use (&$seen) {
            $seen[] = $process;
            $command = $process->command;
            $path = (string) ($command[1] ?? '');

            if (($command[0] ?? '') === 'dpkg-query') {
                return Process::result(output: 'unknown ok not-installed');
            }

            if (($command[0] ?? '') === 'cat' && str_contains($path, 'os-release')) {
                return Process::result(output: "VERSION_CODENAME=noble\n");
            }

            if (($command[0] ?? '') === 'cat' && str_contains($path, 'mongodb-org-8.0.list')) {
                return Process::result(output: "deb [ arch=amd64 signed-by=/etc/apt/keyrings/mongodb-server-8.0.asc ] https://repo.mongodb.org/apt/ubuntu noble/mongodb-org/8.0 multiverse\n");
            }

            if (($command[0] ?? '') === 'cat' && str_contains($path, 'mongodb-server-8.0.asc')) {
                $downloaded = collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'curl');

                return $downloaded
                    ? Process::result(output: "-----BEGIN PGP PUBLIC KEY BLOCK-----\nkey\n")
                    : Process::result(errorOutput: 'missing', exitCode: 1);
            }

            return Process::result(exitCode: 0);
        });

        mongoInstaller()->install();

        expect(collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'curl'))->toBeTrue()
            ->and(collect($seen)->contains(fn ($p) => $p->command === aptCommand('update')))->toBeTrue();
    });

    it('leaves a valid repository pair alone', function () {
        $seen = [];

        Process::fake(function ($process) use (&$seen) {
            $seen[] = $process;
            $command = $process->command;
            $path = (string) ($command[1] ?? '');

            if (($command[0] ?? '') === 'dpkg-query') {
                return Process::result(output: 'unknown ok not-installed');
            }

            if (($command[0] ?? '') === 'cat' && str_contains($path, 'os-release')) {
                return Process::result(output: "VERSION_CODENAME=noble\n");
            }

            if (($command[0] ?? '') === 'cat' && str_contains($path, 'mongodb-org-8.0.list')) {
                return Process::result(output: "deb [ arch=amd64 signed-by=/etc/apt/keyrings/mongodb-server-8.0.asc ] https://repo.mongodb.org/apt/ubuntu noble/mongodb-org/8.0 multiverse\n");
            }

            if (($command[0] ?? '') === 'cat' && str_contains($path, 'mongodb-server-8.0.asc')) {
                return Process::result(output: "-----BEGIN PGP PUBLIC KEY BLOCK-----\nkey\n");
            }

            return Process::result(exitCode: 0);
        });

        mongoInstaller()->install();

        expect(collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'curl'))->toBeFalse()
            ->and(collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'tee'
                && str_contains((string) ($p->command[1] ?? ''), 'mongodb-org-8.0.list')))->toBeFalse()
            ->and(collect($seen)->contains(fn ($p) => $p->command === aptCommand('update')))->toBeFalse();
    });

    it('names the codename the server actually runs, not a guess', function () {
        $seen = [];
        fakeMongoBox($seen);

        mongoInstaller()->install();

        $line = collect($seen)->first(fn ($p) => ($p->command[0] ?? '') === 'tee'
            && str_contains((string) ($p->command[1] ?? ''), 'sources.list.d'));

        // A codename MongoDB does not publish makes a valid list that carries no packages.
        expect($line->input)->toContain('ubuntu jammy/mongodb-org/');
    });

    it('creates the panel account before switching authentication on', function () {
        $seen = [];
        fakeMongoBox($seen);

        mongoInstaller()->install();

        $order = collect($seen)->map(fn ($p) => implode(' ', $p->command).' '.(string) $p->input)->values();

        $createUser = $order->search(fn (string $c) => str_contains($c, 'createUser'));
        $enableAuth = $order->search(fn (string $c) => str_contains($c, 'authorization: enabled'));

        // Once auth is on, only the narrow localhost exception remains to get back in.
        expect($createUser)->not->toBeFalse()
            ->and($enableAuth)->not->toBeFalse()
            ->and($createUser)->toBeLessThan($enableAuth);
    });

    it('switches authentication on, because the package default leaves it off', function () {
        $seen = [];
        fakeMongoBox($seen);

        mongoInstaller()->install();

        $write = collect($seen)->first(fn ($p) => ($p->command[0] ?? '') === 'tee'
            && in_array('-a', $p->command, true));

        // Auth off lets every site on a shared box read every other site's collections.
        expect($write->input)->toContain('authorization: enabled');

        expect(collect($seen)->contains(fn ($p) => $p->command === ['systemctl', 'restart', 'mongod']))->toBeTrue();
    });

    it('stores a credential and never puts it on a command line', function () {
        $seen = [];
        fakeMongoBox($seen);

        mongoInstaller()->install();

        $connection = DatabaseConnection::query()->where('engine', 'mongodb')->firstOrFail();

        expect($connection->username)->toMatch('/^panel_[a-z0-9]{10}$/')
            ->and($connection->password)->not->toBeEmpty()
            ->and($connection->port)->toBe(27017);

        // argv is readable from /proc, and this account holds `root` on every database.
        $password = (string) $connection->password;

        foreach ($seen as $process) {
            expect(implode(' ', $process->command))->not->toContain($password);
        }
    });
});

describe('a server that already had MongoDB', function () {
    it('does not add a repository or install anything', function () {
        $seen = [];
        fakeMongoBox($seen, installedPackage: 'mongodb-org-server');

        mongoInstaller()->install();

        expect(collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'apt-get'))->toBeFalse();
    });

    it('leaves its configuration alone', function () {
        $seen = [];
        fakeMongoBox($seen, installedPackage: 'mongodb-org-server');

        mongoInstaller()->install();

        // Something already connects to this server; turning auth on would take it down.
        expect(collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'tee'
            && in_array('-a', $p->command, true)))->toBeFalse();
    });

    it('stores no credential when it is open, because credentials would break it', function () {
        $seen = [];
        fakeMongoBox($seen, installedPackage: 'mongodb-org-server', authOn: false);

        mongoInstaller()->install();

        $connection = DatabaseConnection::query()->where('engine', 'mongodb')->firstOrFail();

        // MongoEngine builds `mongodb://user:pass@host` whenever a username is set, and a server
        // with authentication off rejects credentials rather than ignoring them.
        expect($connection->username)->toBeNull()
            ->and($connection->password)->toBeNull()
            ->and($connection->port)->toBe(27017);
    });

    it('refuses when it is already locked down and we have no credential', function () {
        $seen = [];
        fakeMongoBox($seen, installedPackage: 'mongodb-org-server', authOn: true);

        // Never guess at a server somebody else configured: refuse instead.
        expect(fn () => mongoInstaller()->install())
            ->toThrow(EngineInstallException::class);

        expect(DatabaseConnection::query()->where('engine', 'mongodb')->exists())->toBeFalse();
    });
});

it('refuses to rewrite a config it could not read', function () {
    $seen = [];

    Process::fake(function ($process) use (&$seen) {
        $seen[] = $process;
        $command = $process->command;

        if (($command[0] ?? '') === 'dpkg-query') {
            return Process::result(output: 'unknown ok not-installed');
        }

        if (($command[0] ?? '') === 'cat' && str_contains((string) ($command[1] ?? ''), 'mongod.conf')) {
            return Process::result(errorOutput: 'No such file or directory', exitCode: 1);
        }

        if (($command[0] ?? '') === 'cat') {
            return Process::result(output: "VERSION_CODENAME=jammy\n");
        }

        if (($command[0] ?? '') === 'test') {
            return Process::result(exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });

    // Unreadable is not empty: appending would leave a config with only our security block
    // (no dbPath, no bindIp) and mongod would never start again.
    expect(fn () => mongoInstaller()->install())->toThrow(EngineInstallException::class);

    expect(collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'tee'
        && in_array('-a', $p->command, true)))->toBeFalse();
});

it('reports the server package as what decides "installed", not the shell', function () {
    $seen = [];

    // `mongosh` alone is a box that talks to somebody else's MongoDB.
    fakeMongoBox($seen, installedPackage: 'mongodb-mongosh');

    expect(mongoInstaller()->installed())->toBeFalse();
});

it('is offerable in the setup page now that it has an installer', function () {
    expect(app(EngineInstallerManager::class)->canInstall('mongodb'))->toBeTrue();
});

it('repairs the panel-created MongoDB key before the bootstrap installer updates apt', function () {
    $path = base_path('../install.sh');

    if (! is_file($path)) {
        $this->markTestSkipped('install.sh is not in this checkout');
    }

    $installer = file_get_contents($path);
    $repair = strpos($installer, 'repair_panel_mongodb_repository');
    // The package step's first refresh: install_source_tools' (only when a
    // tool is missing) or the one refresh after every source is written.
    $firstUpdate = min(array_filter([
        strpos($installer, 'run_apt "Refreshing system package lists" apt-get update -qq'),
        strpos($installer, 'run_apt "Refreshing package lists" apt-get update -qq'),
    ]));

    expect($repair)->not->toBeFalse()
        ->and($firstUpdate)->not->toBeFalse()
        ->and($repair)->toBeLessThan($firstUpdate)
        ->and($installer)->toContain('signed-by=${old_key}')
        ->and($installer)->toContain('mongodb-server-${series}.asc')
        ->and($installer)->toContain('https://repo.mongodb.org/apt/ubuntu');
});

describe('a retry after a part-finished install', function () {
    it('still enables authentication, because the first attempt found nothing here', function () {
        // Attempt one installs the package then dies; attempt two sees MongoDB present and
        // must not conclude it belongs to somebody else and skip enabling auth.
        $ran = [];

        Process::fake(function ($process) use (&$ran) {
            $args = ($process->command[0] ?? '') === 'sudo'
                ? array_slice($process->command, 2)
                : $process->command;

            $ran[] = implode(' ', $args).' '.(string) $process->input;

            return match (true) {
                // The package IS here now — attempt one put it there.
                ($args[0] ?? '') === 'dpkg-query' => Process::result(output: 'install ok installed'),
                ($args[0] ?? '') === 'cat' && str_contains($args[1] ?? '', 'keyring') => Process::result(output: '-----BEGIN PGP PUBLIC KEY BLOCK-----'),
                default => Process::result(output: ''),
            };
        });

        // …but the install was *requested* when it was not.
        app(MongoDbInstaller::class)->install(wasAbsent: true);

        expect(collect($ran)->contains(fn (string $c) => str_contains($c, 'authorization')))
            ->toBeTrue();
    });

    it('leaves a genuinely pre-existing server alone', function () {
        // Not "always configure": a server that already had MongoDB has clients, and rewriting
        // its config would break them.
        $ran = [];

        Process::fake(function ($process) use (&$ran) {
            $args = ($process->command[0] ?? '') === 'sudo'
                ? array_slice($process->command, 2)
                : $process->command;

            $ran[] = implode(' ', $args).' '.(string) $process->input;

            return match (true) {
                ($args[0] ?? '') === 'dpkg-query' => Process::result(output: 'install ok installed'),
                default => Process::result(output: ''),
            };
        });

        app(MongoDbInstaller::class)->install(wasAbsent: false);

        expect(collect($ran)->contains(fn (string $c) => str_contains($c, 'authorization')))
            ->toBeFalse();
    });
});
