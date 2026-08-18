<?php

use App\Exceptions\Server\Database\EngineInstallException;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Server\Databases\Installers\EngineInstallerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * MongoDB is the one engine that is not in Ubuntu's archive and ships with
 * authentication switched off, so its installer does two things the SQL one
 * never has to: add a repository, and close a door that the package left open.
 *
 * Both are ways to leave a server worse than it was found, which is what these
 * tests are actually about.
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

        // `test -f` on the sources list: absent, so the repository gets added.
        if ($binary === 'test') {
            return Process::result(exitCode: 1);
        }

        if ($binary === 'mongosh') {
            $script = (string) $process->input;
            $unauthenticated = ! str_contains($script, '@127.0.0.1');

            // With auth on, an unauthenticated privileged command is refused —
            // which is exactly how the installer probes for it.
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
        $update = $order->search(fn (string $c) => $c === 'apt-get update');
        $install = $order->search(fn (string $c) => str_starts_with($c, 'apt-get install'));

        // The list on disk is not the index. Without the update in between,
        // apt fails with "Unable to locate package mongodb-org" — which reads
        // as a broken panel rather than a stale index.
        expect($list)->not->toBeFalse()
            ->and($update)->not->toBeFalse()
            ->and($install)->not->toBeFalse()
            ->and($list)->toBeLessThan($update)
            ->and($update)->toBeLessThan($install);
    });

    it('signs the repository with its own keyring, never the global trust store', function () {
        $seen = [];
        fakeMongoBox($seen);

        mongoInstaller()->install();

        $line = collect($seen)->first(fn ($p) => ($p->command[0] ?? '') === 'tee'
            && str_contains((string) ($p->command[1] ?? ''), 'sources.list.d'));

        // A key in the global store signs for *every* repository on the box,
        // not just this one.
        expect($line->input)->toContain('signed-by=/etc/apt/keyrings/')
            ->and($line->input)->toContain('repo.mongodb.org');
    });

    it('names the codename the server actually runs, not a guess', function () {
        $seen = [];
        fakeMongoBox($seen);

        mongoInstaller()->install();

        $line = collect($seen)->first(fn ($p) => ($p->command[0] ?? '') === 'tee'
            && str_contains((string) ($p->command[1] ?? ''), 'sources.list.d'));

        // A codename MongoDB does not publish for adds as a perfectly valid
        // list that then carries no packages at all.
        expect($line->input)->toContain('ubuntu jammy/mongodb-org/');
    });

    it('creates the panel account before switching authentication on', function () {
        $seen = [];
        fakeMongoBox($seen);

        mongoInstaller()->install();

        $order = collect($seen)->map(fn ($p) => implode(' ', $p->command).' '.(string) $p->input)->values();

        $createUser = $order->search(fn (string $c) => str_contains($c, 'createUser'));
        $enableAuth = $order->search(fn (string $c) => str_contains($c, 'authorization: enabled'));

        // After the door closes there is only the localhost exception to get
        // back in, and that is a narrower door than it looks.
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

        // Off by default means every site on a shared box can read every other
        // site's collections — the hole per-app FPM pools were built to close.
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

        // Anything on argv is readable from /proc for the life of the process,
        // and this account holds `root` on every database.
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

        // Their config is not ours to rewrite, and something is already
        // connecting to this server — turning auth on underneath it would take
        // that something down.
        expect(collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'tee'
            && in_array('-a', $p->command, true)))->toBeFalse();
    });

    it('stores no credential when it is open, because credentials would break it', function () {
        $seen = [];
        fakeMongoBox($seen, installedPackage: 'mongodb-org-server', authOn: false);

        mongoInstaller()->install();

        $connection = DatabaseConnection::query()->where('engine', 'mongodb')->firstOrFail();

        // MongoEngine builds `mongodb://user:pass@host` whenever a username is
        // set, and a server with authentication off *rejects* credentials
        // rather than ignoring them. Storing one here would break every later
        // operation against a database that works perfectly well.
        expect($connection->username)->toBeNull()
            ->and($connection->password)->toBeNull()
            ->and($connection->port)->toBe(27017);
    });

    it('refuses when it is already locked down and we have no credential', function () {
        $seen = [];
        fakeMongoBox($seen, installedPackage: 'mongodb-org-server', authOn: true);

        // Guessing at the setup of a server somebody else configured is how you
        // take their database down. Say so instead.
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

    // Unreadable is not the same as empty: appending to a path we could not
    // read leaves a config holding nothing but our security block — no dbPath,
    // no bindIp — and mongod never starts again.
    expect(fn () => mongoInstaller()->install())->toThrow(EngineInstallException::class);

    expect(collect($seen)->contains(fn ($p) => ($p->command[0] ?? '') === 'tee'
        && in_array('-a', $p->command, true)))->toBeFalse();
});

it('reports the server package as what decides "installed", not the shell', function () {
    $seen = [];

    // `mongosh` alone is a box that talks to somebody else's MongoDB. Treating
    // that as installed would have the panel offer databases it cannot make.
    fakeMongoBox($seen, installedPackage: 'mongodb-mongosh');

    expect(mongoInstaller()->installed())->toBeFalse();
});

it('is offerable in the setup page now that it has an installer', function () {
    // The one config value that turns the card from greyed to clickable.
    expect(app(EngineInstallerManager::class)->canInstall('mongodb'))->toBeTrue();
});
