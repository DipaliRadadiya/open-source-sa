<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\LegacyPm2Driver;
use Illuminate\Support\Facades\Process;

/**
 * Applications adopted from the old panel, still run by its PM2 daemon.
 *
 * Adoption cannot restart a customer's site, so the panel has to be able to
 * drive a process whose unit it does not own. These cover the rules that
 * distinguish this from the old panel doing the same thing — every one of
 * which is a bug it shipped.
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

    // `runuser -u <user> --` with an argv array, never a shell string. The old
    // panel built `sudo -H -u <user> bash -c "…"`, which root's shell parses
    // before sudo drops privilege — so a `$(…)` in any value ran as root.
    expect(collect($ran)->contains(fn (string $c) => str_starts_with(
        $c, 'runuser -u appuser -- env PM2_HOME=/home/appuser/.pm2 pm2 restart legacy-api'
    )))->toBeTrue();
});

it('saves after every state change, because the dump is the boot mechanism', function (string $action) {
    $ran = pm2Commands();

    app(LegacyPm2Driver::class)->{$action}(adoptedApp());

    // There is no unit here. A stop that is not saved comes back at the next
    // reboot; an environment change that is not saved reverts to the values it
    // replaced. The old panel saved on start and nowhere else.
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
    // The old panel ran `cleardump` on a single delete, so removing one site
    // removed boot persistence for every other site that user owned.
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

    // `jlist` returns one entry per worker. The old panel took the first match
    // and reported a quarter of a four-worker cluster's memory.
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

it('tells "not in the daemon" apart from "stopped"', function () {
    Process::fake(fn () => Process::result(output: '[]'));

    // The old panel returned 200 with a null body whether the application was
    // gone, the daemon was down, or the wrong user had been asked — so the
    // panel could not distinguish any of them from a healthy lookup.
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

    // Degraded is not down: the site still answers. Saying "stopped" would
    // send someone looking for an outage that is not happening, and saying
    // nothing about the dead worker would hide one that is.
    expect($status['state'])->toBe('online')
        ->and($status['online'])->toBe(1)
        ->and($status['instances'])->toBe(2);
});

it('survives a daemon that answers with something other than JSON', function () {
    Process::fake(fn () => Process::result(output: 'PM2: command not found'));

    expect(app(LegacyPm2Driver::class)->status(adoptedApp()))->toBeNull();
});

it('runs from a directory the site user can always enter', function () {
    // `runuser` keeps the caller's working directory. The caller is the panel,
    // and when the site user cannot enter it PM2 fails to spawn its daemon
    // with `spawn /usr/local/bin/node EACCES` — a message that names the
    // binary and says nothing about the directory that caused it.
    //
    // Diagnosed on a live v7 box: the same command failed from /home/ubuntu
    // (0750, another user's home) and worked from /. It only works today
    // because /var/www/panel happens to be world-traversable, which is a
    // hardening decision nothing connects to PM2 working.
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
