<?php

use App\Jobs\InstallNodeVersion;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Node\NodeOverview;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    config([
        'server.runtimes.node.binary' => '/usr/local/bin/fnm',
        'server.runtimes.node.dir' => '/opt/fnm',
    ]);
});

/**
 * @param  array<int, string>  $installed  versions fnm reports
 */
function fakeNode(bool $fnm = true, array $installed = [], ?string $default = null, bool $systemNode = true): ArrayObject
{
    $runs = new ArrayObject;

    $list = collect($installed)
        ->map(fn (string $v) => "* v{$v}".($v === $default ? ' default' : ''))
        ->implode("\n");

    Process::fake(function ($process) use ($runs, $fnm, $list, $systemNode) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];
        $command = $process->command;

        if ($command[0] === 'which') {
            $wantsFnm = str_contains((string) $command[1], 'fnm');

            return $wantsFnm
                ? Process::result(output: $fnm ? "/usr/local/bin/fnm\n" : '', exitCode: $fnm ? 0 : 1)
                : Process::result(output: $systemNode ? "/usr/bin/node\n" : '', exitCode: $systemNode ? 0 : 1);
        }

        if (str_ends_with((string) $command[0], 'node') && ($command[1] ?? '') === '-v') {
            return Process::result(output: "v24.18.0\n");
        }

        if (str_ends_with((string) $command[0], 'fnm')) {
            return match (true) {
                in_array('list-remote', $command, true) => Process::result(
                    output: "v18.20.4\nv20.11.0\nv20.19.1\nv22.11.0\n"
                ),
                in_array('list', $command, true) => Process::result(output: $list),
                default => Process::result(exitCode: 0),
            };
        }

        return Process::result(exitCode: 0);
    });

    return $runs;
}

function nodeSettings(): array
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/node')->json('node');
}

function nodeCall(string $method, string $uri, array $body = []): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)->json($method, $uri, $body);
}

it('reports the Node that was already on the box, without managing it', function () {
    fakeNode(fnm: false);

    $node = nodeSettings();

    // The normal state of a migrated server. It must be usable and untouched;
    // clobbering it would break whatever already depends on it.
    expect($node['manager'])->toBe('system')
        ->and($node['system']['version'])->toBe('24.18.0')
        ->and($node['system']['path'])->toBe('/usr/bin/node')
        ->and($node['versions'])->toBe([]);
});

it('offers the section even with nothing installed', function () {
    fakeNode(fnm: false, systemNode: false);

    // "Nothing is installed" is exactly what the screen needs to say in order
    // to offer installing something.
    expect(nodeSettings())->not->toBeNull()
        ->and(nodeSettings()['manager'])->toBe('none');
});

it('gives each version the absolute path a systemd unit needs', function () {
    fakeNode(installed: ['20.11.0', '18.20.4'], default: '20.11.0');

    $versions = collect(nodeSettings()['versions'])->keyBy('version');

    // This is the whole reason fnm was chosen over nvm: a unit's ExecStart
    // has no shell to resolve a version name in.
    expect($versions['20.11.0']['path'])->toBe('/opt/fnm/node-versions/v20.11.0/installation/bin/node')
        ->and($versions['20.11.0']['is_default'])->toBeTrue()
        ->and($versions['18.20.4']['is_default'])->toBeFalse();
});

it('sorts versions by number, not by string', function () {
    fakeNode(installed: ['9.11.2', '20.11.0', '18.20.4']);

    // Sorted as text, 9 comes after 20.
    expect(collect(nodeSettings()['versions'])->pluck('version')->all())
        ->toBe(['20.11.0', '18.20.4', '9.11.2']);
});

it('offers one version per major rather than every patch release', function () {
    fakeNode();

    // A dropdown of every Node release ever made is not a dropdown.
    expect(collect(nodeSettings()['installable'])->pluck('version')->all())->toBe(['22.11.0', '20.19.1', '18.20.4']);
});

it('counts how many sites pin each version', function () {
    fakeNode(installed: ['20.11.0']);

    $user = SystemUser::create(['username' => 'n', 'home_path' => '/home/n', 'shell' => '/bin/bash', 'sudo' => false]);
    Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'App',
        'slug' => 'app', 'domain' => 'a.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'web_root' => '/',
        'status' => 'pending', 'node_version' => '20.11.0',
    ]);

    // What makes removing a version refusable rather than a surprise.
    expect(collect(nodeSettings()['versions'])->firstWhere('version', '20.11.0')['in_use_by'])->toBe(1);
});

it('queues an install, once per version however many times it is clicked', function () {
    Queue::fake();
    fakeNode();

    nodeCall('POST', '/api/node/versions', ['version' => '20.11.0'])->assertStatus(202);

    // Two clicks would otherwise start two fnm installs racing over the same
    // directory.
    Queue::assertPushed(InstallNodeVersion::class, 1);
    expect((new InstallNodeVersion('20.11.0'))->uniqueId())
        ->toBe('node-install-20.11.0')
        ->not->toBe((new InstallNodeVersion('22.11.0'))->uniqueId());
});

it('treats installing an already-present version as done, not as an error', function () {
    Queue::fake();
    fakeNode(installed: ['20.11.0']);

    // The outcome the caller wanted is already true.
    nodeCall('POST', '/api/node/versions', ['version' => '20.11.0'])->assertOk();
    Queue::assertNothingPushed();
});

it('rejects anything that is not a plain version number', function () {
    fakeNode();

    // It reaches a command argument; the shape is the guard.
    nodeCall('POST', '/api/node/versions', ['version' => '20; rm -rf /'])
        ->assertUnprocessable()->assertJsonValidationErrors('version');
});

it('moves the symlinks when the default changes, and no unit files', function () {
    $runs = fakeNode(installed: ['20.11.0', '18.20.4'], default: '18.20.4');

    nodeCall('PUT', '/api/node/default', ['default' => '20.11.0'])->assertOk();

    $commands = collect($runs)->pluck('command');
    $bin = '/opt/fnm/node-versions/v20.11.0/installation/bin';

    expect($commands)->toContain(['ln', '-sfn', "{$bin}/node", '/usr/local/bin/node'])
        ->and($commands)->toContain(['ln', '-sfn', "{$bin}/npm", '/usr/local/bin/npm']);

    // A site pinned to 18 keeps the absolute path already in its unit —
    // changing the server default must not migrate a running site.
    expect($commands->flatten()->filter(fn ($a) => str_contains((string) $a, 'systemd')))->toBeEmpty();
});

it('refuses a default that is not installed', function () {
    fakeNode(installed: ['20.11.0']);

    nodeCall('PUT', '/api/node/default', ['default' => '22.11.0'])->assertUnprocessable();
});

it('refuses to remove a version a site depends on, and names the site', function () {
    fakeNode(installed: ['20.11.0', '18.20.4'], default: '20.11.0');

    $user = SystemUser::create(['username' => 'n', 'home_path' => '/home/n', 'shell' => '/bin/bash', 'sudo' => false]);
    Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Checkout',
        'slug' => 'checkout', 'domain' => 'c.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'web_root' => '/',
        'status' => 'pending', 'node_version' => '18.20.4',
    ]);

    // Otherwise the failure is a site that stops booting with no obvious
    // cause.
    nodeCall('DELETE', '/api/node/versions/18.20.4')
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Node 18.20.4 is used by Checkout. Change those sites first.']);
});

it('refuses to remove the default version', function () {
    fakeNode(installed: ['20.11.0'], default: '20.11.0');

    nodeCall('DELETE', '/api/node/versions/20.11.0')->assertUnprocessable();
});

it('removes a version nothing depends on', function () {
    $runs = fakeNode(installed: ['20.11.0', '18.20.4'], default: '20.11.0');

    nodeCall('DELETE', '/api/node/versions/18.20.4')->assertNoContent();

    expect(collect($runs)->pluck('command')->flatten())->toContain('uninstall', '18.20.4');
});

it('updates npm with that version\'s own npm', function () {
    $runs = fakeNode(installed: ['20.11.0']);

    nodeCall('POST', '/api/node/versions/20.11.0/npm')->assertOk();

    // A global npm belongs to whichever version is default, and would update
    // the wrong one.
    expect(collect($runs)->pluck('command'))
        ->toContain(['/opt/fnm/node-versions/v20.11.0/installation/bin/npm', 'install', '-g', 'npm@latest']);
});

it('denies every mutation to a view-only user', function () {
    fakeNode(installed: ['20.11.0']);
    $user = User::factory()->create();
    grantPermission($user, 'node', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/node')->assertOk();

    foreach ([
        ['PUT', '/api/node/default', ['default' => '20.11.0']],
        ['POST', '/api/node/versions', ['version' => '20.11.0']],
        ['DELETE', '/api/node/versions/20.11.0', []],
    ] as [$method, $uri, $body]) {
        $this->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $body)->assertForbidden();
    }
});

it('reads npm from each version own npm, not from whatever is on PATH', function () {
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;
        $command = $process->command;

        return match (true) {
            str_contains(implode(' ', $command), 'fnm') && in_array('list', $command, true) => Process::result(
                output: "* v20.11.0 default\n* v18.20.4\n"
            ),
            str_ends_with((string) ($command[0] ?? ''), '/npm') && in_array('-v', $command, true) => Process::result(
                output: str_contains($command[0], 'v20.11.0') ? "10.2.4\n" : "9.8.1\n"
            ),
            default => Process::result(exitCode: 0),
        };
    });

    $versions = collect(app(NodeOverview::class)->read()['versions'])->keyBy('version');

    // A global `npm -v` reports the default version's npm for every row —
    // the same number next to every version, and wrong for all but one.
    expect($versions['20.11.0']['npm_version'])->toBe('10.2.4')
        ->and($versions['18.20.4']['npm_version'])->toBe('9.8.1');

    $npmCalls = collect($runs)->filter(fn (array $c) => str_ends_with((string) ($c[0] ?? ''), '/npm'));
    expect($npmCalls->every(fn (array $c) => str_starts_with($c[0], '/opt/fnm/')))->toBeTrue();
});

it('reports no npm version rather than a wrong one when it cannot be read', function () {
    Process::fake(function ($process) {
        $command = $process->command;

        return match (true) {
            str_contains(implode(' ', $command), 'fnm') && in_array('list', $command, true) => Process::result(output: "* v20.11.0 default\n"),
            in_array('-v', $command, true) => Process::result(exitCode: 1),
            default => Process::result(exitCode: 0),
        };
    });

    $versions = collect(app(NodeOverview::class)->read()['versions'])->keyBy('version');

    expect($versions['20.11.0']['npm_version'])->toBeNull();
});
