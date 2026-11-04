<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Restart for an application that runs in containers.
 *
 * A Docker app has no PM2 or systemd unit of its own, so the process endpoint
 * refused it with "does not run a process of its own" and the screen had no
 * way to bounce a stuck container short of Pause + Resume (which does not
 * touch the containers) or a shell. The endpoint now routes a container app's
 * `restart` to `docker compose restart`, and these cover what that must and
 * must not do: restart every container, keep the data, and never touch a box
 * it was not asked to.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'docker',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'ghost', 'home_path' => '/home/ghost']);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Ghost',
        'slug' => 'ghost',
        'domain' => 'ghost.test',
        'site_type' => 'docker',
        'serving_profile' => 'docker',
        'status' => 'active',
        'web_root' => 'public_html',
        'image' => 'ghost:5',
        'container_port' => 2368,
        'app_port' => 20001,
    ]);
});

function restartRecorder(): ArrayObject
{
    static $bag = null;

    return $bag ??= new ArrayObject;
}

/**
 * A box whose Docker answers per command.
 *
 * Faked per command rather than blanket: `docker info` is the daemon probe,
 * `compose restart` is the action, and `compose ps --status running` is how the
 * result is checked afterwards. A blanket success would pass a restart that
 * brought nothing back.
 */
function fakeRestartBox(bool $daemon = true, bool $restart = true, bool $running = true): void
{
    restartRecorder()->exchangeArray([]);

    Process::fake(function ($process) use ($daemon, $restart, $running) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        restartRecorder()->append($args);

        if (($args[1] ?? '') === 'info') {
            return $daemon
                ? Process::result(output: "27.3.1\n")
                : Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1);
        }

        if (($args[1] ?? '') === 'compose' && in_array('restart', $args, true)) {
            return $restart
                ? Process::result(output: '')
                : Process::result(errorOutput: 'service "web" is not running', exitCode: 1);
        }

        if (($args[1] ?? '') === 'compose' && in_array('ps', $args, true)) {
            return Process::result(output: $running ? "abc123\ndef456\n" : '');
        }

        return Process::result(exitCode: 0);
    });
}

/** Did any command run with a verb among these (ignoring any `sudo` wrapper)? */
function restartRan(string ...$verbs): bool
{
    foreach (restartRecorder() as $args) {
        foreach ($verbs as $verb) {
            if (in_array($verb, $args, true)) {
                return true;
            }
        }
    }

    return false;
}

function restartUrl(string $action = 'restart'): string
{
    return '/api/applications/'.test()->application->id.'/process/'.$action;
}

function restartHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

it('restarts every container of a container app with compose restart', function () {
    fakeRestartBox();

    $this->withHeaders(restartHeaders())
        ->postJson(restartUrl())
        ->assertOk()
        ->assertJsonPath('application.id', $this->application->id);

    // Compose's `restart` with no service named restarts all of them, in place:
    // same containers, same volumes. Not `up`/`down`, which would recreate, and
    // not `pull`, which would change the image under a person who asked for a
    // restart.
    expect(restartRan('restart'))->toBeTrue()
        ->and(restartRan('up', 'down', 'pull', 'rm', 'create'))->toBeFalse();

    $restart = collect(restartRecorder())->first(fn (array $args): bool => in_array('restart', $args, true));

    // This app's own compose project, and no service named after `restart`, which
    // would restart only that one.
    expect($restart)->toContain('compose', '-p', 'sv-app-'.$this->application->id)
        ->and(end($restart))->toBe('restart')
        ->and($restart[array_search('-f', $restart) + 1])->toEndWith('/compose.yml');
});

it('records the restart like the other process actions', function () {
    fakeRestartBox();

    $this->withHeaders(restartHeaders())->postJson(restartUrl())->assertOk();

    $log = ActivityLog::where('type', 'application')->where('action', 'process_restart')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->subject_id)->toBe($this->application->id)
        ->and($log->properties['name'])->toBe('Ghost');
});

it('is refused without the manage permission, and touches nothing', function () {
    fakeRestartBox();

    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'viewer']));

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson(restartUrl())
        ->assertForbidden();

    expect(restartRecorder()->count())->toBe(0)
        ->and(ActivityLog::where('action', 'process_restart')->exists())->toBeFalse();
});

it('is refused for an anonymous caller', function () {
    fakeRestartBox();

    $this->postJson(restartUrl())->assertUnauthorized();

    expect(restartRecorder()->count())->toBe(0);
});

it('is refused while the app is still being set up', function (string $status) {
    fakeRestartBox();
    $this->application->forceFill(['status' => $status])->save();

    $this->withHeaders(restartHeaders())
        ->postJson(restartUrl())
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.container_restart_not_ready', ['name' => 'Ghost']));

    // Nothing was asked of Docker: a pending app has no compose file, and a
    // provisioning one is mid-`up` — a restart would race it.
    expect(restartRecorder()->count())->toBe(0)
        ->and(ActivityLog::where('action', 'process_restart')->exists())->toBeFalse();
})->with(['pending', 'provisioning', 'failed']);

it('is refused for a paused app, rather than reviving containers behind the unavailable page', function () {
    fakeRestartBox();
    $this->application->forceFill(['disabled_at' => now()])->save();

    $this->withHeaders(restartHeaders())
        ->postJson(restartUrl())
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.container_restart_paused', ['name' => 'Ghost']));

    expect(restartRecorder()->count())->toBe(0);
});

it('is refused, before any container is touched, when Docker does not answer', function () {
    fakeRestartBox(daemon: false);

    $this->withHeaders(restartHeaders())
        ->postJson(restartUrl())
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.container_restart_docker_down', ['name' => 'Ghost']));

    expect(restartRan('restart'))->toBeFalse()
        ->and(ActivityLog::where('action', 'process_restart')->exists())->toBeFalse();
});

it('reports a restart that failed, with a reference, and does not log it as done', function () {
    fakeRestartBox(restart: false);

    $response = $this->withHeaders(restartHeaders())
        ->postJson(restartUrl())
        ->assertStatus(500)
        ->assertJsonPath('message', __('errors/application.process_failed', ['action' => 'restart']));

    expect($response->json('reference'))->not->toBeEmpty()
        ->and(ActivityLog::where('action', 'process_restart')->exists())->toBeFalse();
});

it('does not call a restart a success when nothing is running afterwards', function () {
    // `compose restart` exits 0 for services that were already stopped and
    // stays stopped, so the exit code alone says nothing about the app being up.
    fakeRestartBox(running: false);

    $response = $this->withHeaders(restartHeaders())
        ->postJson(restartUrl())
        ->assertStatus(500)
        ->assertJsonPath('message', __('errors/application.process_failed', ['action' => 'restart']));

    expect($response->json('reference'))->not->toBeEmpty()
        ->and(ActivityLog::where('action', 'process_restart')->exists())->toBeFalse();
});

it('still refuses start and stop for a container app, which Pause and Resume cover', function (string $action) {
    fakeRestartBox();

    $this->withHeaders(restartHeaders())
        ->postJson(restartUrl($action))
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.no_process', ['name' => 'Ghost']));

    expect(restartRecorder()->count())->toBe(0);
})->with(['start', 'stop']);

it('still rejects an action it does not know', function () {
    fakeRestartBox();

    $this->withHeaders(restartHeaders())->postJson(restartUrl('destroy'))->assertNotFound();

    expect(restartRecorder()->count())->toBe(0);
});

it('leaves a site that is not a container on the process path it had', function () {
    fakeRestartBox();
    $php = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Blog',
        'slug' => 'blog',
        'domain' => 'blog.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => 'public_html',
    ]);

    // A PHP site has no process: still the old refusal, and no docker command.
    $this->withHeaders(restartHeaders())
        ->postJson('/api/applications/'.$php->id.'/process/restart')
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/application.no_process', ['name' => 'Blog']));

    expect(restartRecorder()->count())->toBe(0);
});
