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
 * The version was checked for shape, and — once `SupportedNodeVersion` existed
 * — against the range the application runs on. Neither asks the box. Nothing
 * downstream asks it either: the version becomes a path in
 * `NodeRuntime::binaryPath()` that goes straight into the systemd unit's
 * `ExecStart`, and systemd does not stat a binary when a unit is written. So
 * the site was created, reported Active, and answered 502 on every request
 * from a vhost proxying to a port nobody was ever going to listen on.
 *
 * The same hole as `php_version` — see `PhpVersionAvailabilityTest` — one
 * runtime along.
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

    // One version on the box. `fakeFnm` is redeclared per test that needs a
    // different answer.
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
        ], $overrides));
}

it('refuses a Node version the server does not have', function () {
    // Inside Uptime Kuma's supported range, so `SupportedNodeVersion` is happy
    // — this is the question nothing was asking.
    createNodeVersionSite(['node_version' => '22.9.0'])
        ->assertJsonValidationErrors('node_version');

    expect(Application::query()->count())->toBe(0);
});

it('accepts the version that is installed', function () {
    createNodeVersionSite(['node_version' => '22.11.0'])->assertSuccessful();

    expect(Application::query()->value('node_version'))->toBe('22.11.0');
});

it('still accepts a site that names no version at all', function () {
    // Null means the server's own Node, resolved at start time rather than
    // written as a path — refusing it here would break every caller that does
    // not send the field, which is all four one-click applications.
    createNodeVersionSite()->assertSuccessful();
});

it('does not refuse anything when no versions could be detected', function () {
    // fnm absent is not "no versions installed", and turning it into one would
    // make a server whose runtime manager cannot be read into a server that
    // cannot host a Node site at all.
    fakeFnm([], fnmPresent: false);

    createNodeVersionSite(['node_version' => '22.9.0'])->assertSuccessful();
});
