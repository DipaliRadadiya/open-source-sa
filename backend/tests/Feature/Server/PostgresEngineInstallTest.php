<?php

use App\Exceptions\Server\Database\EngineInstallException;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Server\Databases\Installers\EngineInstallerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * PostgreSQL is in Ubuntu's archive, so its installer has no repository to add
 * — but it has two problems the MySQL one does not, and both are ways to report
 * a working database that is not there:
 *
 *  - **Its systemd units cannot report a failed cluster.** `postgresql.service`
 *    is `Type=oneshot` with `ExecStart=/bin/true`, and `postgresql@.service`
 *    prefixes its ExecStart with `-`, which tells systemd to ignore a non-zero
 *    exit. Both were read from the shipped package (2026-09-10). Health has to
 *    come from `pg_isready`.
 *  - **root is not `postgres`.** Peer authentication identifies the OS user, so
 *    the `--user=root` trick that makes one code path serve MySQL and MariaDB
 *    is simply refused here.
 *
 * These tests are about those two, plus not minting a second superuser on every
 * re-run.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();
});

function postgresInstaller()
{
    return app(EngineInstallerManager::class)->installer('postgresql');
}

/**
 * @param  string  $clusters  what `pg_lsclusters --no-header` prints
 * @param  bool  $ready  whether `pg_isready` succeeds
 */
function fakePostgresBox(array &$seen, bool $installed = false, string $clusters = "16 main 5432 online postgres /var/lib/postgresql/16/main /var/log/postgresql/postgresql-16-main.log\n", bool $ready = true): void
{
    Process::fake(function ($process) use (&$seen, $installed, $clusters, $ready) {
        $seen[] = $process;
        $command = $process->command;
        $binary = $command[0] ?? '';

        return match (true) {
            $binary === 'dpkg-query' => Process::result(
                output: $installed ? 'install ok installed' : 'unknown ok not-installed'
            ),
            $binary === 'pg_lsclusters' => Process::result(output: $clusters),
            $binary === 'pg_isready' => $ready
                ? Process::result(output: '127.0.0.1:5432 - accepting connections')
                : Process::result(exitCode: 2, errorOutput: 'no response'),
            default => Process::result(exitCode: 0),
        };
    });
}

/** Every command run, as flat strings. */
function postgresCommands(array $seen): array
{
    return array_map(
        fn ($p) => is_array($p->command) ? implode(' ', $p->command) : (string) $p->command,
        $seen,
    );
}

it('is offered as installable', function () {
    // The catalog decides whether the setup card shows a button at all.
    expect(app(EngineInstallerManager::class)->canInstall('postgresql'))->toBeTrue();
});

it('reaches the cluster as the postgres user, not as root', function () {
    $seen = [];
    fakePostgresBox($seen, installed: true);

    postgresInstaller()->install(wasAbsent: false);

    // MySQL and MariaDB share one installer because `root@localhost`
    // authenticates by *being* OS root, which ServerOps already is. Peer auth
    // makes that refuse here: root is not postgres.
    $psql = collect(postgresCommands($seen))->first(fn (string $c) => str_contains($c, 'psql'));

    expect($psql)->toContain('runuser -u postgres')
        ->and($psql)->not->toContain('--user=root')
        // Same measurement as the engine: without this psql exits 0 on a
        // script whose statements failed, and this one creates a SUPERUSER.
        ->and($psql)->toContain('--set=ON_ERROR_STOP=1');
});

it('proves the cluster answers instead of believing systemd', function () {
    $seen = [];
    fakePostgresBox($seen, installed: true);

    postgresInstaller()->install(wasAbsent: false);

    $commands = postgresCommands($seen);

    // Neither unit can report a failed cluster — postgresql.service is
    // `ExecStart=/bin/true`, and postgresql@.service ignores its own exit
    // code. So systemctl is used to *start*, and never to conclude.
    expect(collect($commands)->contains(fn (string $c) => str_starts_with($c, 'pg_isready')))->toBeTrue();
});

it('fails when the cluster does not answer, however cheerful systemctl was', function () {
    $seen = [];
    fakePostgresBox($seen, installed: true, ready: false);

    // systemctl is faked as succeeding here, which is exactly what it does on
    // a real box with a dead cluster.
    expect(fn () => postgresInstaller()->install(wasAbsent: false))
        ->toThrow(EngineInstallException::class);
});

it('starts the cluster it found rather than one it assumed', function () {
    $seen = [];
    // Not `16-main`: a different major and a non-default cluster name, which
    // is what a guess would get wrong.
    fakePostgresBox($seen, installed: true, clusters: "17 panel 5433 online postgres /var/lib/postgresql/17/panel /var/log/x.log\n");

    postgresInstaller()->install(wasAbsent: false);

    expect(collect(postgresCommands($seen))->contains(fn (string $c) => str_contains($c, 'postgresql@17-panel')))->toBeTrue();
});

it('refuses when the package is installed but no cluster exists', function () {
    $seen = [];
    fakePostgresBox($seen, installed: true, clusters: '');

    // A real state — a failed initdb, or a cluster dropped by hand. "Start it
    // again" is not the fix, so it gets a reason of its own rather than being
    // reported as unreachable.
    expect(fn () => postgresInstaller()->install(wasAbsent: false))
        ->toThrow(EngineInstallException::class);
});

it('stores a loopback connection, not a socket one', function () {
    $seen = [];
    fakePostgresBox($seen, installed: true);

    postgresInstaller()->install(wasAbsent: false);

    $connection = DatabaseConnection::query()->where('engine', 'postgresql')->first();

    // A socket connection lands on peer auth and would be refused for the
    // panel's Linux user. Ubuntu's default pg_hba already allows
    // scram-sha-256 from 127.0.0.1, so this needs no edit to a file other
    // things on the box may depend on.
    expect($connection->connection_type)->toBe('tcp')
        ->and($connection->host)->toBe('127.0.0.1')
        ->and($connection->port)->toBe(5432)
        ->and($connection->username)->toStartWith('panel_')
        ->and($connection->password)->not->toBeEmpty();
});

it('rotates the password on a re-run instead of minting a second superuser', function () {
    $seen = [];
    fakePostgresBox($seen, installed: true);

    postgresInstaller()->install(wasAbsent: false);
    $first = DatabaseConnection::query()->where('engine', 'postgresql')->first();

    postgresInstaller()->install(wasAbsent: false);
    $second = DatabaseConnection::query()->where('engine', 'postgresql')->first();

    // Both this and the endpoint that calls it are re-runnable. A fresh random
    // name each time would leave a trail of full-privilege roles behind.
    expect($second->username)->toBe($first->username)
        ->and($second->password)->not->toBe($first->password)
        ->and(DatabaseConnection::query()->where('engine', 'postgresql')->count())->toBe(1);
});

it('does not install packages on a server that already had postgres', function () {
    $seen = [];
    fakePostgresBox($seen, installed: true);

    postgresInstaller()->install(wasAbsent: false);

    // `wasAbsent` is recorded when the install is *requested*, because asking
    // the box is only right the first time: a retry after a part-finished
    // attempt finds the package present and would otherwise conclude the
    // server belongs to somebody else.
    expect(collect(postgresCommands($seen))->contains(fn (string $c) => str_contains($c, 'apt-get install')))->toBeFalse();
});

it('installs the archive package on a server that had none', function () {
    $seen = [];
    fakePostgresBox($seen, installed: false);

    postgresInstaller()->install(wasAbsent: true);

    $apt = collect(postgresCommands($seen))->first(fn (string $c) => str_contains($c, 'apt-get install'));

    // Ubuntu's own archive — no repository to add, unlike MongoDB. The
    // metapackage tracks whichever major the distribution ships.
    expect($apt)->toContain('postgresql');
});
