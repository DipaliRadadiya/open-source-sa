<?php

use App\Enums\SupervisorMode;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Services\Server\Sync\Discoverers\Pm2Discoverer;
use Illuminate\Support\Facades\Process;

/**
 * Finding out that a migrated site is run by PM2 rather than by a unit.
 *
 * Nothing on disk says so. An application discovered from its vhost looks like
 * any other site, and treating it as one means writing a systemd unit for a
 * port something else already holds — the first deploy fails on a site that
 * was working.
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

    $this->run = SyncRun::create(['status' => 'running', 'started_at' => now()]);
});

function syncApp(array $overrides = []): Application
{
    $application = Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'API',
        'domain' => 'api.test',
        'site_type' => 'git',
        'serving_profile' => 'node',
        'status' => 'active',
        'web_root' => '/',
        'node_version' => '20.11.0',
        'start_command' => 'node server.js',
    ], $overrides));

    // `slug` is assigned by CreateApplication, not by the model, and without
    // one `rootPath()` falls back to the system user's home.
    $application->forceFill([
        'slug' => $overrides['slug'] ?? Application::uniqueSlug((string) ($overrides['name'] ?? 'API')),
    ])->save();

    return $application->fresh(['systemUser']);
}

function jlist(array $processes): void
{
    Process::fake(fn ($p) => str_contains(implode(' ', (array) $p->command), 'jlist')
        ? Process::result(output: json_encode($processes))
        : Process::result(output: ''));
}

function pm2Process(string $name, string $cwd, array $env = []): array
{
    return [
        'name' => $name,
        'pm2_env' => array_merge(['pm_cwd' => $cwd, 'status' => 'online', 'exec_mode' => 'fork_mode'], $env),
        'monit' => ['memory' => 100, 'cpu' => 1],
    ];
}

it('attributes a process to the site its working directory is inside', function () {
    $application = syncApp();

    jlist([pm2Process('legacy-api', $application->rootPath().'/public_html')]);

    $items = app(Pm2Discoverer::class)->discover($this->run);

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['application_id'])->toBe($application->id)
        ->and($items[0]['attributes']['process_name'])->toBe('legacy-api');
});

it('counts a cluster as one application, not one per worker', function () {
    $application = syncApp();
    $cwd = $application->rootPath().'/public_html';

    // `jlist` returns an entry per worker. The old panel read the first and
    // reported a quarter of a four-worker cluster.
    jlist([
        pm2Process('legacy-api', $cwd, ['exec_mode' => 'cluster_mode']),
        pm2Process('legacy-api', $cwd, ['exec_mode' => 'cluster_mode']),
        pm2Process('legacy-api', $cwd, ['exec_mode' => 'cluster_mode']),
    ]);

    $items = app(Pm2Discoverer::class)->discover($this->run);

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['instances'])->toBe(3);
});

it('marks the application rather than creating anything', function () {
    $application = syncApp();

    jlist([pm2Process('legacy-api', $application->rootPath().'/public_html')]);

    $items = app(Pm2Discoverer::class)->discover($this->run);
    $adopted = app(Pm2Discoverer::class)->adopt($items[0]);

    expect(Application::query()->count())->toBe(1)
        ->and($adopted->id)->toBe($application->id);

    $fresh = $application->fresh();

    // This is what routes every later start, stop, deploy and status through
    // the PM2 driver instead of systemctl.
    expect($fresh->supervisor_mode)->toBe(SupervisorMode::Pm2)
        ->and($fresh->pm2_process_name)->toBe('legacy-api');
});

it('records the worker count the daemon is actually running', function () {
    $application = syncApp();
    $cwd = $application->rootPath().'/public_html';

    jlist([pm2Process('legacy-api', $cwd), pm2Process('legacy-api', $cwd)]);

    app(Pm2Discoverer::class)->adopt(app(Pm2Discoverer::class)->discover($this->run)[0]);

    expect($application->fresh()->process_instances)->toBe(2);
});

it('does not let one site swallow another whose path it prefixes', function () {
    // Slugs make siblings, not nested roots — but `/home/appuser/api` is a
    // string prefix of `/home/appuser/api-staging`, so a naive `str_starts_with`
    // hands staging's process to production. The boundary is the trailing
    // separator, and this is the case that proves it is there.
    $production = syncApp(['name' => 'API', 'domain' => 'api.test']);
    $staging = syncApp(['name' => 'API Staging', 'domain' => 'staging.test']);

    expect($staging->rootPath())->toStartWith($production->rootPath());

    jlist([pm2Process('staging-api', $staging->rootPath().'/public_html')]);

    $items = app(Pm2Discoverer::class)->discover($this->run);

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['application_id'])->toBe($staging->id);
});

it('reports a process it cannot attribute rather than guessing', function () {
    syncApp();

    // The old agent runs things of its own, and so do customers.
    jlist([pm2Process('someone-elses-thing', '/opt/random')]);

    $items = app(Pm2Discoverer::class)->discover($this->run);

    expect($items)->toHaveCount(1)
        ->and($items[0]['skip'])->toBe('unmatched_directory')
        ->and($items[0])->not->toHaveKey('attributes');
});

it('says nothing about an application already known to be on PM2', function () {
    $application = syncApp(['supervisor_mode' => 'pm2', 'pm2_process_name' => 'legacy-api']);

    jlist([pm2Process('legacy-api', $application->rootPath().'/public_html')]);

    // Re-running Sync must not re-report what the last run settled.
    expect(app(Pm2Discoverer::class)->discover($this->run))->toBeEmpty();
});

it('treats a server with no daemon as having nothing, not as a failure', function () {
    syncApp();

    // The overwhelmingly common case: a box the old panel never touched.
    Process::fake(fn () => Process::result(exitCode: 127, errorOutput: 'pm2: not found', output: ''));

    expect(app(Pm2Discoverer::class)->discover($this->run))->toBeEmpty();
});

it('writes nothing to the server while discovering', function () {
    $application = syncApp();
    $ran = new ArrayObject;

    Process::fake(function ($p) use ($ran, $application) {
        $ran[] = implode(' ', (array) $p->command);

        return str_contains(implode(' ', (array) $p->command), 'jlist')
            ? Process::result(output: json_encode([pm2Process('legacy-api', $application->rootPath().'/public_html')]))
            : Process::result(output: '');
    });

    app(Pm2Discoverer::class)->discover($this->run);

    // The contract every discoverer keeps, and here the only safe option:
    // these are the customer's running sites.
    foreach ($ran as $command) {
        expect($command)->toContain('jlist');
    }
});

it('refuses to let a site with no directory of its own claim anything', function () {
    // `slug` is what gives a site a directory. Without one `rootPath()` is the
    // whole home, so this row would match every process the account runs — and
    // adopting it would mark the wrong site as PM2-supervised.
    $orphan = syncApp(['name' => 'Nameless']);
    $orphan->forceFill(['slug' => null])->save();

    $real = syncApp(['name' => 'Real', 'domain' => 'real.test']);

    jlist([pm2Process('real-api', $real->rootPath().'/public_html')]);

    $items = app(Pm2Discoverer::class)->discover($this->run);

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['application_id'])->toBe($real->id);
});

it('asks each OS user once, not each site', function () {
    syncApp(['name' => 'One', 'domain' => 'one.test']);
    syncApp(['name' => 'Two', 'domain' => 'two.test']);
    syncApp(['name' => 'Three', 'domain' => 'three.test']);

    $calls = new ArrayObject;
    Process::fake(function ($p) use ($calls) {
        $calls[] = 1;

        return Process::result(output: '[]');
    });

    app(Pm2Discoverer::class)->discover($this->run);

    // PM2's state is per user. Three sites under one account is one daemon.
    expect(count($calls))->toBe(1);
});
