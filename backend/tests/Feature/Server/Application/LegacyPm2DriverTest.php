<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\LegacyPm2Driver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;

/**
 * Applications adopted from the old panel, still run by its PM2 daemon.
 *
 * Adoption cannot restart a customer's site, so the panel must drive a process whose unit it
 * does not own. These cover the rules where this driver differs from the old panel's.
 */
beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'mern', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->su = SystemUser::create([
        'username' => 'appuser', 'home_path' => '/home/appuser',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function adoptedApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'API',
        'domain' => 'api.test',
        'site_type' => 'git',
        'serving_profile' => 'node',
        'status' => 'active',
        'web_root' => '/',
        'node_version' => '20.11.0',
        'app_port' => 3000,
        'start_command' => 'node server.js',
        'supervisor_mode' => 'pm2',
        'pm2_process_name' => 'legacy-api',
    ], $overrides));
}

/** Every command the driver ran, as flat strings. */
function pm2Commands(): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($p) use ($ran) {
        $args = ($p->command[0] ?? '') === 'sudo' ? array_slice((array) $p->command, 2) : (array) $p->command;
        $ran[] = implode(' ', $args);

        return Process::result(output: '[]');
    });

    return $ran;
}

it('runs pm2 as the site user, naming the daemon explicitly', function () {
    $ran = pm2Commands();

    app(LegacyPm2Driver::class)->restart(adoptedApp());

    // `runuser -u <user> --` with an argv array, never a shell string: a shell string is parsed by
    // root's shell before privilege is dropped, so a `$(…)` in any value would run as root.
    expect(collect($ran)->contains(fn (string $c) => str_starts_with(
        $c, 'runuser -u appuser -- env PM2_HOME=/home/appuser/.pm2 pm2 restart legacy-api'
    )))->toBeTrue();
});

it('saves after every state change, because the dump is the boot mechanism', function (string $action) {
    $ran = pm2Commands();

    app(LegacyPm2Driver::class)->{$action}(adoptedApp());

    // There is no unit here: a stop or environment change that is not saved is undone at the next
    // reboot, so every change must save.
    expect(collect($ran)->contains(fn (string $c) => str_contains($c, 'pm2 save --force')))->toBeTrue();
})->with(['start', 'stop', 'restart', 'reload']);

it('saves even when the change failed, because that is when they diverge', function () {
    $ran = new ArrayObject;
    Process::fake(function ($p) use ($ran) {
        $line = implode(' ', (array) $p->command);
        $ran[] = $line;

        return str_contains($line, 'pm2 restart')
            ? Process::result(exitCode: 1, errorOutput: 'process not found', output: '')
            : Process::result(output: '');
    });

    app(LegacyPm2Driver::class)->restart(adoptedApp());

    expect(collect($ran)->contains(fn (string $c) => str_contains($c, 'pm2 save --force')))->toBeTrue();
});

it('never clears the dump, which belongs to every app the user owns', function () {
    $ran = pm2Commands();

    app(LegacyPm2Driver::class)->remove(adoptedApp());

    expect(collect($ran)->contains(fn (string $c) => str_contains($c, 'delete legacy-api')))->toBeTrue()
        ->and(collect($ran)->contains(fn (string $c) => str_contains($c, 'cleardump')))->toBeFalse();
    // Deleting one site must not run `cleardump`, which removes boot persistence for every other
    // site of that user.
});

it('aggregates every instance rather than stopping at the first', function () {
    Process::fake(fn ($p) => str_contains(implode(' ', (array) $p->command), 'jlist')
        ? Process::result(output: json_encode([
            ['name' => 'legacy-api', 'pm2_env' => ['status' => 'online', 'restart_time' => 2], 'monit' => ['memory' => 100, 'cpu' => 5]],
            ['name' => 'legacy-api', 'pm2_env' => ['status' => 'online', 'restart_time' => 0], 'monit' => ['memory' => 150, 'cpu' => 3]],
            ['name' => 'legacy-api', 'pm2_env' => ['status' => 'stopped', 'restart_time' => 1], 'monit' => ['memory' => 0, 'cpu' => 0]],
            ['name' => 'someone-else', 'pm2_env' => ['status' => 'online'], 'monit' => ['memory' => 999, 'cpu' => 99]],
        ]))
        : Process::result(output: ''));

    $status = app(LegacyPm2Driver::class)->status(adoptedApp());

    // `jlist` returns one entry per worker; taking the first match undercounts a cluster.
    expect($status)->toMatchArray([
        'state' => 'online',
        'instances' => 3,
        'online' => 2,
        'memory' => 250,
        'restarts' => 3,
    ]);

    // And another application's numbers are not this application's.
    expect($status['memory'])->not->toBe(1249);
});

it('reports CPU and the pid of a running worker, as v7 did', function () {
    Process::fake(fn () => Process::result(output: json_encode([
        ['name' => 'legacy-api', 'pid' => 0, 'pm2_env' => ['status' => 'stopped'], 'monit' => ['memory' => 0, 'cpu' => 0]],
        ['name' => 'legacy-api', 'pid' => 3131, 'pm2_env' => ['status' => 'online'], 'monit' => ['memory' => 10, 'cpu' => 7.5]],
    ])));

    $status = app(LegacyPm2Driver::class)->status(adoptedApp());

    // The stopped worker's 0 is not a process.
    expect($status['pid'])->toBe(3131)
        ->and($status['cpu'])->toBe(7.5);
});

it('tells "not in the daemon" apart from "stopped"', function () {
    Process::fake(fn () => Process::result(output: '[]'));

    // A gone application, a down daemon and a wrong user must each be distinguishable from a
    // healthy lookup, not a 200 with a null body.
    $application = adoptedApp();

    expect(app(LegacyPm2Driver::class)->status($application))->toBeNull()
        ->and(app(LegacyPm2Driver::class)->active($application))->toBeFalse();
});

it('reports a partly-down cluster as online, with the counts to prove it', function () {
    Process::fake(fn () => Process::result(output: json_encode([
        ['name' => 'legacy-api', 'pm2_env' => ['status' => 'online'], 'monit' => ['memory' => 10, 'cpu' => 1]],
        ['name' => 'legacy-api', 'pm2_env' => ['status' => 'stopped'], 'monit' => ['memory' => 0, 'cpu' => 0]],
    ])));

    $status = app(LegacyPm2Driver::class)->status(adoptedApp());

    // Degraded is not down: the site still answers. Saying "stopped" would mislead, and hiding the
    // dead worker would too.
    expect($status['state'])->toBe('online')
        ->and($status['online'])->toBe(1)
        ->and($status['instances'])->toBe(2);
});

it('survives a daemon that answers with something other than JSON', function () {
    Process::fake(fn () => Process::result(output: 'PM2: command not found'));

    expect(app(LegacyPm2Driver::class)->status(adoptedApp()))->toBeNull();
});

it('runs from a directory the site user can always enter', function () {
    // `runuser` keeps the caller's working directory. If the site user cannot enter it, PM2 fails
    // with `spawn /usr/local/bin/node EACCES`, which names the binary, not the directory.
    $cwds = new ArrayObject;

    Process::fake(function ($p) use ($cwds) {
        $cwds[] = $p->path;

        return Process::result(output: '[]');
    });

    $application = adoptedApp();

    app(LegacyPm2Driver::class)->restart($application);
    app(LegacyPm2Driver::class)->status($application);
    app(LegacyPm2Driver::class)->processesFor('appuser', '/home/appuser');

    expect(collect($cwds))->not->toBeEmpty();

    foreach ($cwds as $cwd) {
        expect($cwd)->toBe('/');
    }
});

it('falls back to the application name when adoption recorded none', function () {
    $ran = pm2Commands();

    app(LegacyPm2Driver::class)->stop(adoptedApp(['pm2_process_name' => null]));

    expect(collect($ran)->contains(fn (string $c) => str_contains($c, 'pm2 stop API')))->toBeTrue();
});

it('reads no processes without an error in the log when PM2 is not installed for the user', function () {
    // `env` exits 127 when it cannot find pm2: nothing to read, not a failed
    // server command.
    Process::fake(['*' => Process::result(exitCode: 127, errorOutput: "env: 'pm2': No such file or directory")]);

    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->once()->with('server-ops')->andReturn($logger);

    expect(app(LegacyPm2Driver::class)->processesFor('appuser', '/home/appuser'))->toBeNull();

    $logger->shouldHaveReceived('info')->once()->with('server operation', Mockery::on(
        fn (array $context): bool => $context['op'] === 'pm2_jlist_user'
            && $context['exit_code'] === 127
            && $context['expected_exit'] === true,
    ));
    $logger->shouldNotHaveReceived('error');
});

it('still logs an error when pm2 jlist fails for another reason', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'pm2 crashed')]);

    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->once()->with('server-ops')->andReturn($logger);

    expect(app(LegacyPm2Driver::class)->processesFor('appuser', '/home/appuser'))->toBeNull();

    $logger->shouldHaveReceived('error')->once()->with('server operation', Mockery::on(
        fn (array $context): bool => $context['op'] === 'pm2_jlist_user' && $context['expected_exit'] === false,
    ));
});
