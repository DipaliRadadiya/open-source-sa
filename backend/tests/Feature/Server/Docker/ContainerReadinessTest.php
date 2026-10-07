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
 * @param  array{curl?: string|list<string>, state?: string, running?: bool, exposed?: string, logs?: string}  $box
 */
function fakeReadinessBox(array $box = []): ArrayObject
{
    $ran = new ArrayObject;
    $curls = (array) ($box['curl'] ?? '200');

    Process::fake(function ($process) use ($box, &$curls, $ran) {
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

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'inspect') {
            return Process::result(output: '0 '.now()->subSeconds(5)->toIso8601ZuluString());
        }

        if (in_array('logs', $args, true)) {
            return Process::result(output: $box['logs'] ?? "app-1  | starting\napp-1  | Server running on port 5230\n");
        }

        if (in_array('--status', $args, true)) {
            return Process::result(output: ($box['running'] ?? true) ? "abc123\n" : '');
        }

        if (in_array('--format', $args, true) && in_array('json', $args, true)) {
            return Process::result(output: json_encode(['Name' => 'sv-app-1-app-1', 'State' => $box['state'] ?? 'running']));
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
        ->and($fresh->last_failure['log'])->toContain('Server running on port 5230');

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
    fakeReadinessBox(['curl' => '000', 'exposed' => '{"5230/tcp":{}}']);

    expect(applyMemos()->reason)->toBe('container_not_answering')
        ->and($this->application->fresh()->last_failure['params'])->toMatchArray(['port' => 5230, 'seconds' => 3]);
});

it('stops at a restart loop with the container\'s last words', function () {
    fakeReadinessBox(['state' => 'restarting', 'logs' => "app-1  | python: can't open file '/app/./changedetection.py'\n"]);

    $e = applyMemos();

    expect($e->step)->toBe('container_restarting')
        ->and($e->reason)->toBe('container_restarting')
        ->and($this->application->fresh()->container_status)->toBe('restarting')
        ->and($this->application->fresh()->last_failure['params']['last_line'])
        ->toBe("python: can't open file '/app/./changedetection.py'");
});

it('stops at a container that exited', function () {
    fakeReadinessBox(['running' => false]);

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

it('leaves one-click apps to their installers', function () {
    // The file's origin is what decides, not its text: a ghost row is a one-click.
    $this->application->forceFill(['site_type' => 'ghost'])->save();
    $ran = fakeReadinessBox(['curl' => '000']);

    app(ContainerSupervisor::class)->apply($this->application->fresh(), '/home/memos/memos/public_html');

    expect(collect($ran)->contains(fn ($args) => $args[0] === 'curl'))->toBeFalse()
        ->and($this->application->fresh()->container_status)->toBeNull();
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
