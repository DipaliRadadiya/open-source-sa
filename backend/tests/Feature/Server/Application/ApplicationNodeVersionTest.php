<?php

use App\Enums\SupervisorMode;
use App\Jobs\ChangeApplicationNodeVersion;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
| Junior re-test #12: a site's Node version could not be changed — a PUT with
| `node_version` answered 200 and ignored it — so a version any site used could
| never be removed. Now: switch the unit, restart, ask the site for a page, and
| put the previous unit back if it does not answer.
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    config(['server.applications.readiness.delay' => 0, 'server.applications.readiness.attempts' => 2]);

    $this->systemUser = SystemUser::create(['username' => 'kumaowner', 'home_path' => '/home/kumaowner', 'shell' => '/bin/bash', 'sudo' => false]);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Status', 'slug' => 'status', 'domain' => 'status.test',
        'site_type' => 'uptimekuma', 'serving_profile' => 'node', 'status' => 'active',
        'node_version' => '24.21.0', 'app_port' => 3001, 'start_command' => 'node server/server.js',
    ]);
});

/**
 * A server with Node 22, 24 and 26 installed. The application answers only
 * while its unit names a version in `$answersOn`; `running` is what
 * `systemctl is-active` says before the switch.
 *
 * @param  array<int, string>  $answersOn
 */
function nodeSwitchServer(array $answersOn = ['22.22.0', '24.21.0', '26.10.0'], bool $running = true): ArrayObject
{
    $runs = new ArrayObject(['commands' => [], 'units' => []]);
    $unit = new ArrayObject(['current' => '']);

    Process::fake(function ($process) use ($runs, $unit, $answersOn, $running) {
        $command = ($process->command[0] ?? '') === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $line = implode(' ', $command);
        $runs['commands'] = [...$runs['commands'], $command];

        if (str_contains($line, 'fnm') && in_array('list', $command, true)) {
            return Process::result(output: "* v24.21.0 default\n* v22.22.0\n* v26.10.0\n");
        }

        if (($command[0] ?? '') === 'tee' && str_ends_with((string) end($command), '.service')) {
            $unit['current'] = (string) $process->input;
            $runs['units'] = [...$runs['units'], $unit['current']];
        }

        if (($command[0] ?? '') === 'systemctl' && ($command[1] ?? '') === 'is-active') {
            return Process::result(exitCode: $running ? 0 : 3);
        }

        if (($command[0] ?? '') === 'curl') {
            foreach ($answersOn as $version) {
                if (str_contains($unit['current'], "/v{$version}/")) {
                    return Process::result(output: '302');
                }
            }

            return Process::result(output: '000');
        }

        return Process::result();
    });

    return $runs;
}

function switchNode(string $version, ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)
        ->putJson('/api/applications/'.test()->application->id.'/node-version', ['node_version' => $version]);
}

function restarts(ArrayObject $runs): int
{
    return collect($runs['commands'])->filter(fn (array $c) => $c === ['systemctl', 'restart', 'sv-app-'.test()->application->id.'.service'])->count();
}

it('switches a running site to another installed version and restarts it', function () {
    $runs = nodeSwitchServer();

    switchNode('22.22.0')->assertStatus(202);

    $application = $this->application->fresh();

    expect($application->node_version)->toBe('22.22.0')
        ->and($application->node_version_target)->toBeNull()
        ->and(end($runs['units']))->toContain('/opt/fnm/node-versions/v22.22.0/installation/bin')
        ->and(end($runs['units']))->not->toContain('v24.21.0')
        ->and(restarts($runs))->toBe(1)
        ->and(ActivityLog::query()->where('action', 'node_version_changed')->first()?->properties)
        ->toMatchArray(['from' => '24.21.0', 'to' => '22.22.0']);
});

it('puts the previous version back when the site does not answer on the new one', function () {
    $runs = nodeSwitchServer(answersOn: ['24.21.0']);

    switchNode('26.10.0')->assertStatus(202);

    $application = $this->application->fresh();

    expect($application->node_version)->toBe('24.21.0')
        // The unit on disk is the old one again, and was restarted on it.
        ->and(end($runs['units']))->toContain('/v24.21.0/')
        ->and(restarts($runs))->toBe(2)
        ->and(ActivityLog::query()->where('action', 'node_version_change_failed')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'node_version_changed')->exists())->toBeFalse();

    $this->actingAs($this->admin)->getJson("/api/applications/{$application->id}")
        ->assertJsonPath('application.node_version', '24.21.0')
        ->assertJsonPath('application.node_version_change.status', 'failed')
        ->assertJsonPath('application.node_version_change.target', '26.10.0')
        ->assertJsonPath('application.node_version_change.reason', 'did_not_start')
        ->assertJsonPath('application.node_version_change.message', __('errors/node.change_failed.did_not_start', ['target' => '26.10.0', 'current' => '24.21.0']));
});

it('says so when the way back fails too', function () {
    nodeSwitchServer(answersOn: []);

    switchNode('22.22.0')->assertStatus(202);

    expect($this->application->fresh()->node_version_failed_reason)->toBe('rollback_failed')
        ->and($this->application->fresh()->node_version)->toBe('24.21.0');
});

it('lets a failed switch be tried again, and clears the failure when it works', function () {
    nodeSwitchServer(answersOn: ['24.21.0']);
    switchNode('26.10.0')->assertStatus(202);

    nodeSwitchServer();
    switchNode('26.10.0')->assertStatus(202);

    expect($this->application->fresh())
        ->node_version->toBe('26.10.0')
        ->node_version_failed_reason->toBeNull()
        ->node_version_target->toBeNull();
});

it('does not start a site that was stopped', function () {
    $runs = nodeSwitchServer(running: false);

    switchNode('22.22.0')->assertStatus(202);

    expect($this->application->fresh()->node_version)->toBe('22.22.0')
        ->and(end($runs['units']))->toContain('/v22.22.0/')
        ->and(restarts($runs))->toBe(0);
});

it('only records the version for a site with no process of its own', function () {
    // A static git site: the version is what its next build uses.
    $runs = nodeSwitchServer();
    $this->application->forceFill(['site_type' => 'git', 'start_command' => null, 'app_port' => null, 'rendering_type' => 'static'])->save();

    switchNode('22.22.0')->assertStatus(202);

    expect($this->application->fresh()->node_version)->toBe('22.22.0')
        ->and($runs['units'])->toBe([])
        ->and(restarts($runs))->toBe(0);
});

it('answers 200 and does nothing for the version the site is already on', function () {
    $runs = nodeSwitchServer();

    switchNode('24.21.0')->assertOk();

    expect($runs['units'])->toBe([])->and(restarts($runs))->toBe(0);
});

it('refuses what cannot be switched to', function (array $payload, ?Closure $setup = null) {
    nodeSwitchServer();
    $setup?->call($this);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/node-version", $payload)
        ->assertJsonValidationErrors('node_version');

    expect($this->application->fresh()->node_version)->toBe('24.21.0');
})->with([
    'not installed' => [['node_version' => '20.11.0']],
    'not a version' => [['node_version' => '22; rm -rf /']],
    'missing' => [[]],
    'below what the type runs on' => [['node_version' => '22.22.0'], fn () => $this->application->forceFill(['site_type' => 'n8n'])->save()],
    'not a Node site' => [['node_version' => '22.22.0'], fn () => $this->application->forceFill(['site_type' => 'wordpress', 'serving_profile' => 'php'])->save()],
    'an adopted PM2 process' => [['node_version' => '22.22.0'], fn () => $this->application->forceFill(['supervisor_mode' => SupervisorMode::Pm2])->save()],
    'a switch already running' => [['node_version' => '22.22.0'], fn () => $this->application->forceFill(['node_version_target' => '26.10.0'])->save()],
]);

it('refuses node_version on the generic update instead of ignoring it', function () {
    nodeSwitchServer();

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}", ['node_version' => '22.22.0'])
        ->assertJsonPath('errors.node_version.0', __('errors/node.change_use_endpoint'));

    expect($this->application->fresh()->node_version)->toBe('24.21.0');
});

it('needs manage on applications', function () {
    nodeSwitchServer();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'application', view: true, manage: false);

    switchNode('22.22.0', $viewer)->assertForbidden();

    expect($this->application->fresh()->node_version)->toBe('24.21.0');
});

it('marks a switch the worker never finished as failed, so it can be tried again', function () {
    $this->application->forceFill(['node_version_target' => '22.22.0'])->save();

    (new ChangeApplicationNodeVersion($this->application->id, '22.22.0'))->failed(null);

    expect($this->application->fresh()->node_version_failed_reason)->toBe('worker');
});
