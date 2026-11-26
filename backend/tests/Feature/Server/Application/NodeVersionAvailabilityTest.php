<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/**
 * A Node site may only be created on a Node version this server actually has.
 *
 * The version becomes a path in `NodeRuntime::binaryPath()` that goes into the systemd unit's
 * `ExecStart`, and systemd does not stat a binary when a unit is written: a missing version
 * would give an Active site answering 502. Same check as `PhpVersionAvailabilityTest`.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'mern', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->su = SystemUser::create([
        'username' => 'apps', 'home_path' => '/home/apps',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);

    config([
        'server.runtimes.node.binary' => '/usr/local/bin/fnm',
        'server.runtimes.node.dir' => '/opt/fnm',
    ]);

    // One version installed; tests needing a different answer call `fakeFnm` again.
    fakeFnm(['22.11.0']);
});

/**
 * @param  array<int, string>  $installed  what fnm reports, or none at all
 */
function fakeFnm(array $installed, bool $fnmPresent = true): void
{
    $list = collect($installed)->map(fn (string $v) => "* v{$v}")->implode("\n");

    Process::fake(function ($process) use ($list, $fnmPresent) {
        if ($process->command[0] === 'which') {
            return str_contains((string) $process->command[1], 'fnm')
                ? Process::result(output: $fnmPresent ? "/usr/local/bin/fnm\n" : '', exitCode: $fnmPresent ? 0 : 1)
                : Process::result(output: "/usr/bin/node\n");
        }

        if (str_ends_with((string) $process->command[0], 'fnm')) {
            return Process::result(output: $list);
        }

        return Process::result(output: '');
    });
}

function createNodeVersionSite(array $overrides = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson('/api/applications', array_merge([
            'system_user_id' => test()->su->id,
            'name' => 'Status',
            'domain' => 'status.example.com',
            'site_type' => 'uptimekuma',
            'admin_username' => 'admin',
            'admin_password' => 'Kuma-Pass-2026',
        ], $overrides));
}

it('refuses a Node version the server does not have', function () {
    // Inside Uptime Kuma's supported range, so only the installed-version check can refuse it.
    createNodeVersionSite(['node_version' => '22.9.0'])
        ->assertJsonValidationErrors('node_version');

    expect(Application::query()->count())->toBe(0);
});

it('accepts the version that is installed', function () {
    createNodeVersionSite(['node_version' => '22.11.0'])->assertSuccessful();

    expect(Application::query()->value('node_version'))->toBe('22.11.0');
});

it('still accepts a site that names no version at all', function () {
    // Null means the server's own Node, resolved at start time; refusing it would break every
    // caller that omits the field, such as the one-click applications.
    createNodeVersionSite()->assertSuccessful();
});

it('does not refuse anything when no versions could be detected', function () {
    // fnm absent is not "no versions installed": an unreadable runtime manager must not make
    // the server unable to host a Node site.
    fakeFnm([], fnmPresent: false);

    createNodeVersionSite(['node_version' => '22.9.0'])->assertSuccessful();
});
