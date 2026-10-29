<?php

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseGuard;

/*
 * The guard after the 14 and 28 Oct wipes: a cached config or a non-SQLite
 * connection stops the run before RefreshDatabase gets a connection.
 *
 * The unit cases give the guard an application that is never booted, so no
 * connection is ever opened. The end-to-end cases run a probe test in a child
 * Pest process; the MySQL they describe is 127.0.0.1:1, where nothing listens,
 * so even a broken guard could not reach a real database.
 */

function guardRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @param  array<string, mixed>  $sqlite */
function guardApp(string $default = 'sqlite', array $sqlite = [], bool $cached = false): Application
{
    $app = new Application(guardRoot());
    $app->instance('config_loaded_from_cache', $cached);
    $app->instance('config', new Repository(['database' => [
        'default' => $default,
        'connections' => [
            'sqlite' => array_replace(['driver' => 'sqlite', 'url' => null, 'database' => ':memory:'], $sqlite),
            'mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'panel_live'],
        ],
    ]]));

    return $app;
}

it('lets an in-memory SQLite run through', function () {
    expect(TestDatabaseGuard::refusal(guardApp()))->toBeNull();

    TestDatabaseGuard::check(guardApp());
});

it('refuses a cached configuration, naming the connection and database', function () {
    $refusal = TestDatabaseGuard::refusal(guardApp('mysql', cached: true));

    expect($refusal)
        ->toStartWith('Refusing to run tests: the configuration is cached')
        ->toContain('connection "mysql", database "panel_live"')
        ->toContain('Run tests in a worktree; delete bootstrap/cache/config.php.');
});

it('refuses a cached configuration even when it says SQLite in memory', function () {
    expect(TestDatabaseGuard::refusal(guardApp(cached: true)))->toContain('the configuration is cached');
});

it('refuses a MySQL default connection', function () {
    expect(fn () => TestDatabaseGuard::check(guardApp('mysql')))->toThrow(
        RuntimeException::class,
        'Refusing to run tests: the default connection is not sqlite (connection "mysql", database "panel_live"). Run tests in a worktree; delete bootstrap/cache/config.php.',
    );
});

it('refuses a connection named sqlite that drives another engine', function () {
    expect(TestDatabaseGuard::refusal(guardApp(sqlite: ['driver' => 'mysql', 'database' => 'panel_live'])))
        ->toContain('the default connection is not sqlite');
});

it('refuses a sqlite connection with a URL, which would override its database', function () {
    expect(TestDatabaseGuard::refusal(guardApp(sqlite: ['url' => 'mysql://root@127.0.0.1:1/panel_live'])))
        ->toContain('has a URL');
});

it('refuses SQLite files that are not testing files under database/', function (string $database) {
    expect(TestDatabaseGuard::refusal(guardApp(sqlite: ['database' => $database])))
        ->toContain('the SQLite database is not :memory:')
        ->toContain('database "'.$database.'"');
})->with([
    'the app database' => fn () => guardRoot().'/database/database.sqlite',
    'a testing file outside database/' => fn () => sys_get_temp_dir().'/testing.sqlite',
    'a climb out of database/' => fn () => guardRoot().'/database/../testing.sqlite',
    'a relative path' => 'testing.sqlite',
    'no database at all' => '',
]);

it('allows a testing SQLite file under database/', function () {
    expect(TestDatabaseGuard::refusal(guardApp(sqlite: ['database' => guardRoot().'/database/testing.sqlite'])))->toBeNull();
});

/** Run the probe file in a child Pest process with extra environment. */
function guardProbe(string $directory, array $env): Process
{
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/pest', 'tests/Fixtures/TestDatabaseGuard/GuardProbeTest.php'],
        guardRoot(),
        ['GUARD_PROBE_MARKER' => $directory.'/marker.json'] + $env,
        timeout: 120,
    );
    $process->run();

    return $process;
}

function guardScratch(): string
{
    $directory = guardRoot().'/storage/framework/testing/guard-'.Str::random(8);
    mkdir($directory, 0700, true);

    return $directory;
}

function guardCleanup(string $directory): void
{
    array_map('unlink', glob($directory.'/*') ?: []);
    rmdir($directory);
}

it('stops a whole run whose cached config points at MySQL, before any test body', function () {
    $directory = guardScratch();

    // What `config:cache` writes from a live .env that names MySQL.
    $cache = new Process(
        [PHP_BINARY, 'tests/Fixtures/TestDatabaseGuard/cache-config.php', $directory.'/config.php'],
        guardRoot(),
        ['APP_ENV' => 'production', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 's12_guard_never_touched', 'DB_URL' => ''],
        timeout: 60,
    );
    $cache->mustRun();

    try {
        $probe = guardProbe($directory, ['APP_CONFIG_CACHE' => $directory.'/config.php']);
        $output = $probe->getOutput().$probe->getErrorOutput();

        expect($probe->getExitCode())->not->toBe(0)
            ->and($output)->toContain('Refusing to run tests: the configuration is cached')
            ->and($output)->toContain('connection "mysql", database "s12_guard_never_touched"')
            ->and($output)->toContain('Run tests in a worktree; delete bootstrap/cache/config.php.')
            ->and(substr_count($output, 'Refusing to run tests'))->toBe(1)
            ->and(file_exists($directory.'/marker.json'))->toBeFalse()
            ->and(file_exists($directory.'/marker.json.second'))->toBeFalse();
    } finally {
        guardCleanup($directory);
    }
});

it('keeps SQLite in memory when the shell environment names MySQL', function () {
    $directory = guardScratch();

    try {
        $probe = guardProbe($directory, [
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 's12_guard_never_touched',
            'DB_URL' => 'mysql://root@127.0.0.1:1/s12_guard_never_touched',
        ]);

        expect($probe->getExitCode())->toBe(0, $probe->getOutput().$probe->getErrorOutput())
            ->and(json_decode((string) file_get_contents($directory.'/marker.json'), true))
            ->toBe(['connection' => 'sqlite', 'database' => ':memory:']);
    } finally {
        guardCleanup($directory);
    }
});
