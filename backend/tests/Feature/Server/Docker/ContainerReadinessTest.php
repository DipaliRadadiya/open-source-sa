<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ContainerReadinessCheck;
use App\Services\Server\Applications\ContainerSupervisor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * DS-03: after `compose up`, wait for the site to answer, and say why when it
 * does not.
 *
 * The case this exists for, measured on both test panels: Memos deployed with
 * container port 8082 ran happily on 5230, `compose up` exited 0, the panel said
 * Running, and every request was a 502. Now the deploy fails with "Nothing
 * answers on container port 8082 — the image listens on 5230" and the
 * container's last log lines beside it.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'memos', 'home_path' => '/home/memos']);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Memos', 'slug' => 'memos', 'domain' => 'memos.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'neosmemo/memos:0.31.0', 'container_port' => 8082, 'app_port' => 3001,
        'status' => 'active',
    ]);
});

/**
 * `health` is what the image's HEALTHCHECK reports, one value per ask, the
 * last repeating; '' (the default) is an image without one.
 *
 * @param  array{curl?: string|list<string>, state?: string, running?: bool, exposed?: string, logs?: string, health?: string|list<string>, health_log?: string}  $box
 */
function fakeReadinessBox(array $box = []): ArrayObject
{
    $ran = new ArrayObject;
    $curls = (array) ($box['curl'] ?? '200');
    $healths = (array) ($box['health'] ?? '');

    Process::fake(function ($process) use ($box, &$curls, &$healths, $ran) {
        $args = $process->command;

        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }

        $ran[] = $args;

        if (($args[0] ?? '') === 'curl') {
            return Process::result(output: count($curls) > 1 ? array_shift($curls) : $curls[0]);
        }

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'image') {
            return Process::result(output: $box['exposed'] ?? 'null');
        }

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'inspect' && in_array('{{json .State.Health.Log}}', $args, true)) {
            return Process::result(output: $box['health_log'] ?? 'null');
        }

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'inspect') {
            return Process::result(output: '0 '.now()->subSeconds(5)->toIso8601ZuluString());
        }

        if (in_array('logs', $args, true)) {
            return Process::result(output: $box['logs'] ?? "app-1  | starting\napp-1  | Server running on port 5230\n");
        }

        if (in_array('--status', $args, true)) {
            return Process::result(output: ($box['running'] ?? true) ? "abc123\n" : '');
        }

        // Every `ps --format json` takes the next health value: the check
        // asks once for the health and once more for the restart state.
        if (in_array('--format', $args, true) && in_array('json', $args, true)) {
            $health = count($healths) > 1 ? array_shift($healths) : $healths[0];

            return Process::result(output: json_encode(['Name' => 'sv-app-1-app-1', 'State' => $box['state'] ?? 'running', 'Health' => $health]));
        }

        return Process::result(exitCode: 0);
    });

    return $ran;
}

function applyMemos(): ?ProvisioningFailedException
{
    try {
        app(ContainerSupervisor::class)->apply(test()->application, '/home/memos/memos/public_html');

        return null;
    } catch (ProvisioningFailedException $e) {
        return $e;
    }
}

it('passes a container that answers, and clears an earlier failure', function () {
    $this->application->forceFill([
        'container_status' => 'not_answering',
        'last_failure' => ['reason' => 'container_not_answering', 'params' => ['port' => 80, 'seconds' => 90], 'log' => 'x'],
    ])->save();

    fakeReadinessBox(['curl' => '302']);

    expect(applyMemos())->toBeNull();

    $fresh = $this->application->fresh();
    expect($fresh->container_status)->toBe('running')
        ->and($fresh->last_failure)->toBeNull();
});

it('accepts any HTTP status as an answer, a 404 or 500 included', function (string $code) {
    fakeReadinessBox(['curl' => $code]);

    expect(applyMemos())->toBeNull();
})->with(['404', '401', '500']);

it('keeps waiting while nothing listens yet, then passes', function () {
    // A first boot that is still migrating refuses connections for a while.
    $ran = fakeReadinessBox(['curl' => ['000', '000', '200']]);

    expect(applyMemos())->toBeNull()
        ->and(collect($ran)->filter(fn ($args) => $args[0] === 'curl')->count())->toBe(3);
});

it('names the port the image really listens on when nothing answers', function () {
    fakeReadinessBox(['curl' => '000', 'exposed' => '{"5230/tcp":{}}']);

    $e = applyMemos();

    expect($e)->not->toBeNull()
        ->and($e->step)->toBe('verify_serving')
        ->and($e->reason)->toBe('container_port_mismatch');

    $fresh = $this->application->fresh();
    expect($fresh->container_status)->toBe('not_answering')
        ->and($fresh->last_failure['reason'])->toBe('container_port_mismatch')
        ->and($fresh->last_failure['params'])->toMatchArray(['port' => 8082, 'image_ports' => '5230'])
        ->and($fresh->last_failure['log'])->toContain('Server running on port 5230')
        // Encrypted at rest (DS-08): a first boot prints connection strings,
        // and the row is in every database dump and panel backup.
        ->and((string) $fresh->getRawOriginal('last_failure'))->not->toContain('Server running')
        ->and((string) $fresh->getRawOriginal('last_failure'))->not->toContain('container_port_mismatch');

    $admin = User::factory()->admin()->create();
    $request = Request::create('/');
    $request->setUserResolver(fn () => $admin);

    $failure = ApplicationResource::make($fresh)->toArray($request)['last_failure'];

    expect($failure['message'])->toContain('Nothing answers on container port 8082 — the image listens on 5230')
        ->and($failure['message'])->toContain('Last log line: Server running on port 5230')
        ->and($failure['last_line'])->toBe('Server running on port 5230');
});

it('says nothing answered in time when the port is the declared one', function () {
    $this->application->forceFill(['container_port' => 5230])->save();
    config(['server.docker.readiness.timeout' => 12, 'server.docker.readiness.interval' => 3]);
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    fakeReadinessBox(['curl' => '000', 'exposed' => '{"5230/tcp":{}}']);

    // The seconds actually waited, not the configured number.
    expect(applyMemos()->reason)->toBe('container_not_answering')
        ->and($this->application->fresh()->last_failure['params'])->toMatchArray(['port' => 5230, 'seconds' => 12]);
});

it('waits against the clock, not a count of tries (DS-09)', function () {
    // A port that accepts and then hangs: each probe burns curl's whole
    // --max-time. Counted as 30 tries of 3 s, "90 seconds" waited ~258, past
    // the panel's own 300 s FastCGI timeout on the synchronous PUT /container.
    config(['server.docker.readiness.timeout' => 90, 'server.docker.readiness.interval' => 3]);
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $started = now();
    $maxTimes = [];

    Process::fake(function ($process) use (&$maxTimes) {
        $args = $process->command;

        if (in_array('curl', $args, true)) {
            $maxTime = (int) $args[array_search('--max-time', $args, true) + 1];
            $maxTimes[] = $maxTime;
            $this->travel($maxTime)->seconds();

            return Process::result(output: '000');
        }

        if (in_array('--status', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        if (in_array('--format', $args, true) && in_array('json', $args, true)) {
            return Process::result(output: json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']));
        }

        return Process::result(output: in_array('inspect', $args, true) ? '0 '.now()->subMinutes(10)->toIso8601ZuluString() : '');
    });

    $e = applyMemos();
    $waited = $started->diffInSeconds(now());

    expect($e->reason)->toBe('container_not_answering')
        // The last probe is cut to the time left.
        ->and($waited)->toBeLessThanOrEqual(90)
        ->and($waited)->toBeGreaterThanOrEqual(86)
        ->and(max($maxTimes))->toBeLessThanOrEqual(5)
        ->and($this->application->fresh()->last_failure['params']['seconds'])->toBe((int) round($waited));
});

it('caps a configured wait well under the request timeout (DS-09)', function () {
    config(['server.docker.readiness.timeout' => 600, 'server.docker.readiness.interval' => 3]);
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $started = now();
    fakeReadinessBox(['curl' => '000']);

    applyMemos();

    expect($started->diffInSeconds(now()))->toBeLessThanOrEqual(ContainerReadinessCheck::MAX_TIMEOUT)
        ->and(ContainerReadinessCheck::MAX_TIMEOUT)->toBeLessThan(300);
});

/**
 * A supervisor whose containers report the given restart-loop samples in
 * turn, the last one repeating. Everything else is the real check.
 *
 * @param  list<bool>  $looping
 */
function readinessContainers(array $looping): ContainerSupervisor
{
    $containers = Mockery::mock(ContainerSupervisor::class);
    $containers->shouldReceive('crashLooping')->andReturnUsing(function () use (&$looping) {
        return count($looping) > 1 ? array_shift($looping) : $looping[0];
    });
    $containers->shouldReceive('running')->andReturnTrue();
    $containers->shouldReceive('health')->andReturnNull();
    $containers->shouldReceive('logs')->andReturn("app-1  | Error: connect ECONNREFUSED 127.0.0.1:5432\n");

    return $containers;
}

it('lets a stack whose app restarts while its database starts come up (DS-09)', function (bool $pasted) {
    // The common shape of a pasted file — app + postgres, restart:
    // unless-stopped. The app exits once on ECONNREFUSED, is restarted, and
    // answers. One sighting of that restart used to fail the deploy.
    if ($pasted) {
        $this->application->forceFill(['compose' => "services:\n  app:\n    image: ghcr.io/umami-software/umami\n  db:\n    image: postgres:16\n", 'container_port' => null])->save();
    }

    config(['server.docker.readiness.timeout' => 90, 'server.docker.readiness.interval' => 3]);
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    fakeReadinessBox(['curl' => ['000', '000', '000', '200']]);

    app(ContainerReadinessCheck::class)->verify($this->application->fresh(), '/home/memos/memos/public_html', readinessContainers([true, true, true, false]));

    expect($this->application->fresh()->container_status)->toBe('running');
})->with(['generated' => false, 'pasted' => true]);

it('still stops a single image that keeps restarting, before the deadline (DS-09)', function () {
    config(['server.docker.readiness.timeout' => 90, 'server.docker.readiness.interval' => 3, 'server.docker.readiness.restart_grace' => 30]);
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $started = now();
    fakeReadinessBox(['curl' => '000']);

    try {
        app(ContainerReadinessCheck::class)->verify($this->application->fresh(), '/home/memos/memos/public_html', readinessContainers([true]));
        $this->fail('a container that never stops restarting must fail');
    } catch (ProvisioningFailedException $e) {
        expect($e->reason)->toBe('container_restarting')
            ->and($started->diffInSeconds(now()))->toBeLessThan(40);
    }
});

it('waits out the deadline on a pasted file, then names the restart (DS-09)', function () {
    $this->application->forceFill(['compose' => "services:\n  app:\n    image: nginx\n", 'container_port' => null])->save();
    config(['server.docker.readiness.timeout' => 90, 'server.docker.readiness.interval' => 3]);
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $started = now();
    fakeReadinessBox(['curl' => '000']);

    try {
        app(ContainerReadinessCheck::class)->verify($this->application->fresh(), '/home/memos/memos/public_html', readinessContainers([true]));
        $this->fail('a stack that never comes up must fail');
    } catch (ProvisioningFailedException $e) {
        expect($e->reason)->toBe('container_restarting')
            ->and($started->diffInSeconds(now()))->toBeGreaterThanOrEqual(85);
    }
});

it('does not name the host port as the container port (DS-09)', function () {
    // A pasted file has no single container port; app_port 3001 is the
    // panel's loopback port on the host.
    $this->application->forceFill(['compose' => "services:\n  app:\n    image: nginx\n", 'container_port' => null])->save();
    fakeReadinessBox(['curl' => '000']);

    try {
        app(ContainerReadinessCheck::class)->verify($this->application->fresh(), '/home/memos/memos/public_html', readinessContainers([false]));
    } catch (ProvisioningFailedException) {
    }

    $fresh = $this->application->fresh();
    $request = Request::create('/');
    $request->setUserResolver(fn () => User::factory()->admin()->create());
    $message = ApplicationResource::make($fresh)->toArray($request)['last_failure']['message'];

    expect($fresh->last_failure['params'])->not->toHaveKey('port')
        ->and($message)->not->toContain('3001')
        ->and($message)->toContain("Nothing answered on this site's port within");
});

it('stops at a restart loop with the container\'s last words', function () {
    // Nothing answers on a container that keeps dying: curl prints 000.
    fakeReadinessBox(['curl' => '000', 'state' => 'restarting', 'logs' => "app-1  | python: can't open file '/app/./changedetection.py'\n"]);

    $e = applyMemos();

    expect($e->step)->toBe('container_restarting')
        ->and($e->reason)->toBe('container_restarting')
        ->and($this->application->fresh()->container_status)->toBe('restarting')
        ->and($this->application->fresh()->last_failure['params']['last_line'])
        ->toBe("python: can't open file '/app/./changedetection.py'");
});

it('stops at a container that exited', function () {
    fakeReadinessBox(['curl' => '000', 'running' => false]);

    $e = applyMemos();

    expect($e->step)->toBe('container_exited')
        ->and($e->reason)->toBe('container_exited')
        ->and($this->application->fresh()->container_status)->toBe('exited');
});

it('does not fail a site on a probe that never ran', function () {
    // curl printed nothing: nothing was measured, so nothing is concluded.
    fakeReadinessBox(['curl' => '']);

    expect(applyMemos())->toBeNull();
});

it('leaves one-click apps to their installers, and reports them running (DS-14)', function () {
    // The file's origin is what decides, not its text: a ghost row is a one-click.
    // Its check is running + not bouncing, and passing it is now said: the API
    // used to answer null while the panel page said Running.
    $this->application->forceFill(['site_type' => 'ghost'])->save();
    $ran = fakeReadinessBox(['curl' => '000']);

    app(ContainerSupervisor::class)->apply($this->application->fresh(), '/home/memos/memos/public_html');

    expect(collect($ran)->contains(fn ($args) => $args[0] === 'curl'))->toBeFalse()
        ->and($this->application->fresh()->container_status)->toBe('running');
});

it('hides the log from someone who may not read this site\'s logs', function () {
    $this->application->forceFill([
        'last_failure' => ['reason' => 'container_restarting', 'params' => ['last_line' => 'DB_PASSWORD=hunter2'], 'log' => 'DB_PASSWORD=hunter2'],
    ])->save();

    $viewer = User::factory()->create();
    grantPermission($viewer, 'application');
    $request = Request::create('/');
    $request->setUserResolver(fn () => $viewer);

    $failure = ApplicationResource::make($this->application->fresh())->toArray($request)['last_failure'];

    expect($failure['reason'])->toBe('container_restarting')
        ->and($failure['message'])->toBe('The container keeps restarting.')
        ->and($failure['log'])->toBeNull()
        ->and($failure['last_line'])->toBeNull();
});

it('strips the compose prefix from the last log line', function () {
    expect(ContainerReadinessCheck::lastLine("app-1  | one\napp-1  | two\n\n"))->toBe('two')
        ->and(ContainerReadinessCheck::lastLine(''))->toBe('');
});

/*
 * DS-09: a stored `running` is not a running container.
 */

/** `docker ps` answering with these compose projects running; null makes it fail. */
function fakeLiveProjects(?array $projects): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($projects, $ran) {
        $ran[] = $process->command;

        if (in_array('ps', $process->command, true) && in_array('status=running', $process->command, true)) {
            return $projects === null
                ? Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1)
                : Process::result(output: implode("\n", $projects)."\n");
        }

        return Process::result(exitCode: 0);
    });

    return $ran;
}

function readinessResource(Application $application): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => User::factory()->admin()->create());

    return ApplicationResource::make($application)->toArray($request);
}

it('does not show Running for a container that has stopped since the deploy', function () {
    $this->application->forceFill(['container_status' => 'running'])->save();

    fakeLiveProjects(['sv-app-999']);
    expect(readinessResource($this->application->fresh())['container_status'])->toBe('exited');

    app()->forgetScopedInstances();
    fakeLiveProjects(['sv-app-'.$this->application->id]);
    expect(readinessResource($this->application->fresh())['container_status'])->toBe('running');

    // Docker could not be asked: that is not a stopped container.
    app()->forgetScopedInstances();
    fakeLiveProjects(null);
    expect(readinessResource($this->application->fresh())['container_status'])->toBe('running');
});

it('asks Docker once per request, however many sites are listed', function () {
    $this->application->forceFill(['container_status' => 'running'])->save();
    $other = $this->application->replicate();
    $other->forceFill(['name' => 'Memos 2', 'slug' => 'memos2', 'app_port' => 3002, 'domain' => 'memos2.test', 'container_status' => 'running'])->save();

    $ran = fakeLiveProjects([]);

    readinessResource($this->application->fresh());
    readinessResource($other->fresh());

    expect(collect($ran)->filter(fn ($args) => in_array('status=running', $args, true))->count())->toBe(1);
});

it('forgets a passed readiness check once the containers are stopped, started or restarted', function (string $action) {
    $this->application->forceFill(['container_status' => 'running'])->save();
    fakeReadinessBox();

    app(ContainerSupervisor::class)->{$action}($this->application, '/home/memos/memos/public_html');

    expect($this->application->fresh()->container_status)->toBeNull();
})->with(['stop', 'start', 'restart']);

it('keeps a stored failure when the containers are restarted', function () {
    $this->application->forceFill(['container_status' => 'not_answering'])->save();
    fakeReadinessBox();

    app(ContainerSupervisor::class)->restart($this->application, '/home/memos/memos/public_html');

    expect($this->application->fresh()->container_status)->toBe('not_answering');
});

/*
 * DS-12: the cross-check moves the badge both ways, and the check's own
 * docker questions are bounded.
 */

it('drops a stored exit once Docker has the container running again', function () {
    // The mirror of the DS-09 case: the site was fixed and started — Start
    // runs no readiness check — and kept its red "failed" until a deploy.
    $this->application->forceFill([
        'container_status' => 'exited',
        'last_failure' => ['reason' => 'container_exited', 'params' => [], 'log' => 'boom'],
    ])->save();

    fakeLiveProjects(['sv-app-'.$this->application->id]);
    $live = readinessResource($this->application->fresh());

    expect($live['container_status'])->toBeNull()
        ->and($live['last_failure'])->toBeNull();

    // Still down: the failure stands.
    app()->forgetScopedInstances();
    fakeLiveProjects(['sv-app-999']);
    $down = readinessResource($this->application->fresh());

    expect($down['container_status'])->toBe('exited')
        ->and($down['last_failure']['reason'])->toBe('container_exited');

    // Docker could not be asked: that is not a container that came back.
    app()->forgetScopedInstances();
    fakeLiveProjects(null);
    expect(readinessResource($this->application->fresh())['container_status'])->toBe('exited');
});

it('does not read a running container as a fixed one when it never stopped', function (string $stored) {
    // `not_answering` is a running container that does not answer, and a
    // crash loop is running for part of every bounce: neither is fixed by
    // being up.
    $this->application->forceFill([
        'container_status' => $stored,
        'last_failure' => ['reason' => 'container_not_answering', 'params' => ['port' => 8082, 'seconds' => 90], 'log' => 'x'],
    ])->save();

    fakeLiveProjects(['sv-app-'.$this->application->id]);
    $resource = readinessResource($this->application->fresh());

    expect($resource['container_status'])->toBe($stored)
        ->and($resource['last_failure'])->not->toBeNull();
})->with(['not_answering', 'restarting']);

it('asks its docker questions under a short ceiling, not the pull timeout', function () {
    // `compose up` gets the 600 s a pull of gigabytes may need. `ps` and
    // `logs` ran under the same 600 on every try and after the deadline.
    $timeouts = [];

    Process::fake(function ($process) use (&$timeouts) {
        $args = $process->command;
        $op = collect(['up', 'ps', 'logs'])->first(fn (string $word): bool => in_array($word, $args, true));

        if ($op !== null && in_array('compose', $args, true)) {
            $timeouts[$op][] = $process->timeout;
        }

        if (in_array('curl', $args, true)) {
            return Process::result(output: '000');
        }

        if (in_array('--status', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        if (in_array('--format', $args, true) && in_array('json', $args, true)) {
            return Process::result(output: json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']));
        }

        return Process::result(output: in_array('inspect', $args, true) ? '0 '.now()->subMinutes(10)->toIso8601ZuluString() : '');
    });

    config(['server.docker.readiness.timeout' => 2, 'server.docker.readiness.interval' => 0]);

    expect(applyMemos()?->reason)->toBe('container_not_answering')
        ->and(array_unique($timeouts['up']))->toBe([600])
        ->and(max($timeouts['ps']))->toBe(ContainerSupervisor::QUERY_TIMEOUT)
        ->and(max($timeouts['logs']))->toBe(ContainerSupervisor::QUERY_TIMEOUT)
        // What the check can spend after its deadline: one more sample and
        // the diagnostics, still inside the 300 s request.
        ->and(ContainerReadinessCheck::MAX_TIMEOUT + 3 * ContainerSupervisor::QUERY_TIMEOUT)->toBeLessThan(300);
});

/*
 * DS-14: the image's own HEALTHCHECK, one-click status.
 */

it('passes on the image\'s own healthcheck without asking / at all', function () {
    $ran = fakeReadinessBox(['curl' => '000', 'health' => 'healthy']);

    expect(applyMemos())->toBeNull()
        ->and($this->application->fresh()->container_status)->toBe('running')
        ->and(collect($ran)->contains(fn ($args) => $args[0] === 'curl'))->toBeFalse();
});

it('fails an unhealthy container that answers /, with the healthcheck\'s own words', function () {
    // Kanboard on 26.04: `/` answered 200 with a migration error page, Docker
    // said unhealthy, and the panel said Running.
    fakeReadinessBox([
        'curl' => '200',
        'health' => 'unhealthy',
        'health_log' => json_encode([
            ['ExitCode' => 0, 'Output' => 'ok'],
            ['ExitCode' => 22, 'Output' => "curl: (22) The requested URL returned error: 500\n"],
        ]),
    ]);

    $e = applyMemos();
    $fresh = $this->application->fresh();

    expect($e)->not->toBeNull()
        ->and($e->step)->toBe('verify_serving')
        ->and($e->reason)->toBe('container_unhealthy')
        ->and($fresh->container_status)->toBe('unhealthy')
        ->and($fresh->last_failure['params']['check'])->toBe('curl: (22) The requested URL returned error: 500');

    fakeLiveProjects(['sv-app-'.$this->application->id."\tUp 1 minute (unhealthy)"]);
    $shown = readinessResource($fresh);

    expect($shown['container_status'])->toBe('unhealthy')
        ->and($shown['last_failure']['message'])->toContain('health check fails')
        ->and($shown['last_failure']['message'])->toContain('returned error: 500');
});

it('does not ask / while the healthcheck is still starting, then passes on healthy', function () {
    config(['server.docker.readiness.timeout' => 90, 'server.docker.readiness.interval' => 3]);
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    // Two asks per try (health, then the restart state): starting for two tries.
    $ran = fakeReadinessBox(['curl' => '200', 'health' => ['starting', 'starting', 'starting', 'starting', 'healthy']]);

    expect(applyMemos())->toBeNull()
        ->and($this->application->fresh()->container_status)->toBe('running')
        ->and(collect($ran)->contains(fn ($args) => $args[0] === 'curl'))->toBeFalse();
});

it('passes as starting when the healthcheck has no verdict by the deadline but / answers', function () {
    config(['server.docker.readiness.timeout' => 9, 'server.docker.readiness.interval' => 3]);
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $ran = fakeReadinessBox(['curl' => '302', 'health' => 'starting']);

    expect(applyMemos())->toBeNull()
        ->and($this->application->fresh()->container_status)->toBe('starting')
        ->and(collect($ran)->filter(fn ($args) => $args[0] === 'curl')->count())->toBe(1);
});

it('reports the live healthcheck over a stored running', function (string $status, string $shown) {
    $this->application->forceFill(['container_status' => 'running'])->save();

    fakeLiveProjects(['sv-app-'.$this->application->id."\t".$status, "sv-app-999\tUp 2 hours (unhealthy)"]);

    expect(readinessResource($this->application->fresh())['container_status'])->toBe($shown);
})->with([
    ['Up 7 minutes (unhealthy)', 'unhealthy'],
    ['Up 5 seconds (health: starting)', 'starting'],
    ['Up 3 minutes (healthy)', 'running'],
    ['Up 3 minutes', 'running'],
]);

it('clears an unhealthy failure once the healthcheck passes', function () {
    $this->application->forceFill([
        'container_status' => 'unhealthy',
        'last_failure' => ['reason' => 'container_unhealthy', 'params' => ['check' => 'x'], 'log' => 'x'],
    ])->save();

    fakeLiveProjects(['sv-app-'.$this->application->id."\tUp 9 minutes (healthy)"]);
    $shown = readinessResource($this->application->fresh());

    expect($shown['container_status'])->toBe('running')
        ->and($shown['last_failure'])->toBeNull();
});

it('reports a one-click container app as Docker sees it, not null', function () {
    // Ghost on both DS-11 servers: container_status null while the page said Running.
    $this->application->forceFill(['site_type' => 'ghost', 'container_status' => null])->save();

    fakeLiveProjects(['sv-app-'.$this->application->id."\tUp 4 minutes"]);
    expect(readinessResource($this->application->fresh())['container_status'])->toBe('running');

    // Stopped (most likely on purpose): nothing is claimed.
    app()->forgetScopedInstances();
    fakeLiveProjects([]);
    expect(readinessResource($this->application->fresh())['container_status'])->toBeNull();

    // An image site with no check stays null: "up" is not "answering".
    app()->forgetScopedInstances();
    $this->application->forceFill(['site_type' => 'docker'])->save();
    fakeLiveProjects(['sv-app-'.$this->application->id."\tUp 4 minutes"]);
    expect(readinessResource($this->application->fresh())['container_status'])->toBeNull();
});
