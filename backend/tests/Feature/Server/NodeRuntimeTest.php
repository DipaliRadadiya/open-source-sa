<?php

use App\Actions\Server\Application\CreateApplication;
use App\Jobs\InstallNodeVersion;
use App\Models\Application;
use App\Models\NpmRelease;
use App\Models\RuntimeLifecycle;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Runtime\NpmCatalog;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Node\NodeOverview;
use App\Services\Server\Runtimes\NodeRuntime;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
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
        $runs[] = [
            'command' => $process->command,
            'input' => (string) $process->input,
            // npm is a Node script, so what PATH it is given decides whether it
            // runs at all — recorded here so a test can say so.
            'environment' => $process->environment,
        ];
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

describe('versions Node itself has stopped supporting', function () {
    /*
     * 🔴 The report this exists to stop happening again.
     *
     * A user picked Node 21 — dead since June 2024 — for a one-click n8n site.
     * The install ran for five minutes and died compiling `isolated-vm`:
     * native modules ship prebuilt binaries per Node ABI, and nobody builds
     * them for a buried release, so npm fell back to a C++ compile on a server
     * that has no compiler. The version was carrying an EOL badge in the very
     * list it was offered from, and that was not enough.
     */
    it('does not offer a dead line', function () {
        eolNode('18');
        fakeNode();

        expect(collect(nodeSettings()['installable'])->pluck('version')->all())
            ->toBe(['22.11.0', '20.19.1']);
    });

    it('still offers a line the catalog has no answer about', function () {
        // A box with no egress has never refreshed the catalog. Hiding
        // everything there would turn an absent badge into an empty screen —
        // "we have not been told" is not "it is dead".
        lifecycleNode('20', 'lts');
        fakeNode();

        expect(collect(nodeSettings()['installable'])->pluck('version')->all())
            ->toBe(['22.11.0', '20.19.1', '18.20.4']);
    });

    it('offers dead lines again when the operator asks for them', function () {
        // The escape hatch for a server migrating an application that
        // genuinely needs a dead runtime.
        config()->set('server.runtimes.node.offer_eol', true);
        eolNode('18');
        fakeNode();

        expect(collect(nodeSettings()['installable'])->pluck('version')->all())
            ->toBe(['22.11.0', '20.19.1', '18.20.4']);
    });

    it('never hides a dead version that is already installed', function () {
        // Only the offer to add new ones is withdrawn. A site is running on
        // that version; dropping it from the screen would leave the user
        // unable to see, let alone move off, the runtime they are on.
        eolNode('18');
        fakeNode(installed: ['18.20.4', '22.11.0']);

        expect(collect(nodeSettings()['versions'])->pluck('version')->all())
            ->toContain('18.20.4');
    });
});

/** Record a Node major as end of life, the way the lifecycle refresh would. */
function eolNode(string $major): void
{
    lifecycleNode($major, 'eol');
}

function lifecycleNode(string $major, string $status): void
{
    RuntimeLifecycle::create([
        'runtime' => 'node',
        'version' => $major,
        'status' => $status,
        'eol_date' => $status === 'eol' ? '2024-06-01' : '2030-01-01',
    ]);
}

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

it('orders pinned site names without case bias', function () {
    fakeNode(installed: ['20.11.0']);

    $user = SystemUser::create([
        'username' => 'mixednode',
        'home_path' => '/home/mixednode',
        'shell' => '/bin/bash',
        'sudo' => false,
    ]);

    foreach ([
        ['name' => 'Case Zebra', 'slug' => 'case-zebra', 'domain' => 'zebra.test'],
        ['name' => 'case apple', 'slug' => 'case-apple', 'domain' => 'apple.test'],
        ['name' => 'CASE Banana', 'slug' => 'case-banana', 'domain' => 'banana.test'],
    ] as $site) {
        Application::forceCreate($site + [
            'system_user_id' => $user->id,
            'site_type' => 'node',
            'serving_profile' => 'node',
            'web_root' => '/',
            'status' => 'pending',
            'node_version' => '20.11.0',
        ]);
    }

    $version = collect(nodeSettings()['versions'])->firstWhere('version', '20.11.0');

    expect($version['sites'])->toBe(['case apple', 'CASE Banana', 'Case Zebra']);
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

it('restores the prior default when a binary link fails', function () {
    $runs = new ArrayObject;
    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;
        $command = $process->command;

        return match (true) {
            str_contains(implode(' ', $command), 'fnm') && in_array('list', $command, true) => Process::result(
                output: "* v20.11.0\n* v18.20.4 default\n"
            ),
            ($command[0] ?? '') === 'test' => Process::result(exitCode: 0),
            ($command[0] ?? '') === 'ln' && str_contains((string) ($command[2] ?? ''), 'v20.11.0')
                && str_ends_with((string) ($command[2] ?? ''), '/npm') => Process::result(exitCode: 1, errorOutput: 'link failed'),
            default => Process::result(exitCode: 0),
        };
    });

    nodeCall('PUT', '/api/node/default', ['default' => '20.11.0'])->assertStatus(500);

    expect(collect($runs))->toContain([
        '/usr/local/bin/fnm', '--fnm-dir', '/opt/fnm', 'alias', '18.20.4', 'default',
    ]);
    expect(collect($runs))->toContain([
        'ln', '-sfn', '/opt/fnm/node-versions/v18.20.4/installation/bin/node', '/usr/local/bin/node',
    ]);
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
    // A release this Node version can actually run: 20.11.0 does not satisfy
    // npm 11's `^20.17.0 || >=22.9.0`, which is the whole point of pinning.
    NpmRelease::query()->create(['major' => '10', 'version' => '10.9.9', 'node_range' => '^18.17.0 || >=20.5.0']);
    $runs = fakeNode(installed: ['20.11.0']);

    nodeCall('POST', '/api/node/versions/20.11.0/npm')->assertOk();

    // A global npm belongs to whichever version is default, and would update
    // the wrong one. The spec is pinned rather than `@latest` for the reason
    // NodeRuntime::updateNpm() gives.
    expect(collect($runs)->pluck('command'))
        ->toContain(['/opt/fnm/node-versions/v20.11.0/installation/bin/npm', 'install', '-g', 'npm@10.9.9']);
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

it('gives npm a PATH with node on it when updating it', function () {
    // npm is a Node script (`#!/usr/bin/env node`), so it needs `node` on PATH
    // even when run by absolute path. Without it this failed in three
    // milliseconds with "/usr/bin/env: 'node': No such file or directory" — an
    // error about node, from a command about npm, on a box with both installed.
    //
    // `npmVersion()` directly below the method already pinned PATH for exactly
    // this reason; the update path was written without it.
    $runs = fakeNode(installed: ['v24.19.0'], default: 'v24.19.0');

    app(NodeRuntime::class)->updateNpm('24.19.0', '12.0.2');

    $update = collect($runs)->first(fn ($run) => in_array('npm@12.0.2', $run['command'], true));

    expect($update)->not->toBeNull();

    $path = $update['environment']['PATH'] ?? '';
    $binDir = dirname((string) $update['command'][0]);

    // This version's own bin dir, and first: the point of the method is to
    // update npm inside one version, and borrowing another version's node to
    // do it is how the wrong thing gets updated.
    expect($path)->toStartWith($binDir.':');
});

it('installs the newest npm this node version can run, not npm@latest', function () {
    // npm 12 needs Node `^22.22.2 || ^24.15.0 || >=26.0.0`. On Node 20,
    // `npm install -g npm@latest` replaces a working npm with one that cannot
    // start — the button that is meant to keep a version current breaks it.
    NpmRelease::query()->create(['major' => '11', 'version' => '11.19.1', 'node_range' => '^20.17.0 || >=22.9.0']);
    NpmRelease::query()->create(['major' => '12', 'version' => '12.0.2', 'node_range' => '^22.22.2 || ^24.15.0 || >=26.0.0']);

    $runs = fakeNode(installed: ['v20.19.0'], default: 'v20.19.0');

    app(NodeRuntime::class)->updateNpm('20.19.0', app(NpmCatalog::class)->resolveTarget('20.19.0'));

    $specs = collect($runs)
        ->map(fn ($run) => collect($run['command'])->first(fn ($arg) => str_starts_with((string) $arg, 'npm@')))
        ->filter();

    expect($specs->all())->toContain('npm@11.19.1')
        ->and($specs->all())->not->toContain('npm@latest');
});

it('refuses to update npm when it cannot tell which npm to install', function () {
    // This used to fall back to `npm@latest`, on the grounds that a box with
    // no egress was then no worse off than before the catalog existed. But an
    // empty catalog is not a rare offline box -- it is every server whose
    // catalog has never been filled -- and on a Node 20 box `@latest` installs
    // an npm that cannot start. Not knowing what to install is now a refusal.
    Http::fake(['registry.npmjs.org/*' => Http::response(status: 503)]);

    $runs = fakeNode(installed: ['20.19.0'], default: '20.19.0');

    nodeCall('POST', '/api/node/versions/20.19.0/npm')
        ->assertUnprocessable()
        ->assertJsonPath('message', __('errors/node.npm_target_unknown'));

    // The guard that matters: nothing was installed. A refusal that still ran
    // the install would be the old behaviour with a worse status code.
    expect(collect($runs)->contains(fn ($run) => in_array('install', $run['command'], true)))->toBeFalse();
});

it('refreshes the catalog before giving up on it', function () {
    // The catalog is empty here and the registry is reachable, which is the
    // ordinary state of a server that has never run the scheduled refresh.
    // Fetching once beats refusing: the answer is one request away.
    Http::fake(['registry.npmjs.org/*' => Http::response([
        'versions' => [
            '11.19.1' => ['version' => '11.19.1', 'engines' => ['node' => '^20.17.0 || >=22.9.0']],
        ],
    ])]);

    $runs = fakeNode(installed: ['20.19.0'], default: '20.19.0');

    nodeCall('POST', '/api/node/versions/20.19.0/npm')->assertOk();

    expect(collect($runs)->pluck('command')->flatten())->toContain('npm@11.19.1');
});

it('sends the newest npm each version can run beside the one it has', function () {
    NpmRelease::query()->create(['major' => '10', 'version' => '10.9.9', 'node_range' => '^18.17.0 || >=20.5.0']);
    NpmRelease::query()->create(['major' => '12', 'version' => '12.0.2', 'node_range' => '^22.22.2 || ^24.15.0 || >=26.0.0']);

    Process::fake(function ($process) {
        $command = $process->command;

        return match (true) {
            str_contains(implode(' ', $command), 'fnm') && in_array('list', $command, true) => Process::result(
                output: "* v24.19.0 default\n* v18.20.4\n"
            ),
            // Both versions carry the same npm; what differs is how far each
            // one is allowed to go.
            str_ends_with((string) ($command[0] ?? ''), '/npm') && in_array('-v', $command, true) => Process::result(output: "10.9.9\n"),
            default => Process::result(exitCode: 0),
        };
    });

    $versions = collect(app(NodeOverview::class)->read()['versions'])->keyBy('version');

    // Node 24 can reach npm 12 and has 10 — an update worth offering. Node 18
    // already has the newest npm it will ever run, so the button must go away
    // rather than pointing at a release that cannot start on it.
    expect($versions['24.19.0']['npm_latest'])->toBe('12.0.2')
        ->and($versions['24.19.0']['npm_update_available'])->toBeTrue()
        ->and($versions['18.20.4']['npm_latest'])->toBe('10.9.9')
        ->and($versions['18.20.4']['npm_update_available'])->toBeFalse();
});

it('records that the server now has Node, so the create screen stops denying it', function () {
    // `ServerCapabilities::current()` returns the *stored* row and only
    // detects when there is none. install.sh writes that row once — a `lamp`
    // server records `'node' => false` — so installing Node afterwards left
    // the create-application screen insisting Node was not installed on a
    // server that plainly had it, forever. Nothing self-corrected, because
    // nothing detects again while a row exists.
    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'lamp',
        'web_server' => 'apache',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    expect(app(ServerCapabilities::class)->supports('node'))->toBeFalse();

    fakeNode(installed: ['22.11.0']);

    app()->call([new InstallNodeVersion('22.11.0'), 'handle']);

    // A fresh instance: the service memoises the row for the life of a
    // request, and asserting through the same one would pass on the cache
    // rather than on what was written.
    expect(app()->make(ServerCapabilities::class, [])->supports('node'))->toBeTrue();
});

describe('found on a real server, 2026-09-23', function () {
    it('does not offer a pre-1.0 line the catalog says is dead', function () {
        // Node files 0.x by minor — `0.12`, not `0`. Keyed by the major alone,
        // 0.12.18 looked up `0`, found nothing, read "unknown" as "keep", and
        // a release dead since 2016 sat in the install list.
        lifecycleNode('0.12', 'eol');
        lifecycleNode('0.10', 'eol');
        Process::fake(fn ($process) => str_ends_with((string) $process->command[0], 'fnm') && in_array('list-remote', $process->command, true)
            ? Process::result(output: "v0.7.12\nv0.9.12\nv0.10.48\nv0.11.16\nv0.12.18\nv22.11.0\n")
            : Process::result(output: "/usr/local/bin/fnm\n"));

        // The odd dev lines (0.7, 0.9, 0.11) are not in Node's schedule at
        // all; they are dead because a line after them is.
        expect(app(NodeRuntime::class)->installable())->toBe(['22.11.0']);
    });

    it('still offers a line newer than anything the catalog knows', function () {
        // A release the catalog has not caught up with is not dead.
        lifecycleNode('24', 'lts');
        lifecycleNode('20', 'eol');
        Process::fake(fn ($process) => str_ends_with((string) $process->command[0], 'fnm') && in_array('list-remote', $process->command, true)
            ? Process::result(output: "v20.19.1\nv24.1.0\nv26.0.0\n")
            : Process::result(output: "/usr/local/bin/fnm\n"));

        expect(app(NodeRuntime::class)->installable())->toBe(['26.0.0', '24.1.0']);
    });

    it('hands a newly installed version to whoever owns the fnm directory', function () {
        // install.sh gives /opt/fnm to the panel account; fnm runs elevated,
        // so a version added from the Node screen came out root-owned and its
        // npm update — which runs as the panel account — failed with EACCES.
        $runs = new ArrayObject;
        Process::fake(function ($process) use ($runs) {
            $runs[] = $process->command;

            return $process->command[0] === 'stat'
                ? Process::result(output: "panel:panel\n")
                : Process::result();
        });

        app(NodeRuntime::class)->install('22.11.0');

        $commands = collect($runs)->map(fn ($c) => implode(' ', $c));

        expect($commands->search(fn ($c) => str_contains($c, 'install 22.11.0')))
            ->toBeLessThan($commands->search(fn ($c) => $c === 'chown -R panel:panel /opt/fnm/node-versions/v22.11.0'))
            ->and($commands)->toContain('chown -R panel:panel /opt/fnm/node-versions/v22.11.0');
    });

    it('leaves ownership alone when root owns the fnm directory', function () {
        Process::fake(fn ($process) => $process->command[0] === 'stat'
            ? Process::result(output: "root:root\n")
            : Process::result());

        app(NodeRuntime::class)->install('22.11.0');

        Process::assertNotRan(fn ($process) => $process->command[0] === 'chown');
    });

    it('repairs an existing server: links the default and adopts every version', function () {
        $runs = fakeNode(installed: ['22.11.0', '24.1.0'], default: '24.1.0');
        Process::fake(function ($process) use ($runs) {
            $runs[] = ['command' => $process->command];
            $command = $process->command;

            if ($command[0] === 'stat') {
                return Process::result(output: "panel:panel\n");
            }

            if (str_ends_with((string) $command[0], 'fnm')) {
                return in_array('list', $command, true)
                    ? Process::result(output: "* v22.11.0\n* v24.1.0 default\n")
                    : Process::result();
            }

            return Process::result(output: "/usr/local/bin/fnm\n");
        });

        $this->artisan('runtimes:repair-node')
            ->expectsOutputToContain('node, npm and npx point at 24.1.0')
            ->assertSuccessful();

        $commands = collect($runs)->map(fn ($r) => implode(' ', $r['command']));

        foreach (['node', 'npm', 'npx'] as $bin) {
            expect($commands)->toContain("ln -sfn /opt/fnm/node-versions/v24.1.0/installation/bin/{$bin} /usr/local/bin/{$bin}");
        }

        expect($commands)->toContain('chown -R panel:panel /opt/fnm/node-versions/v22.11.0')
            ->and($commands)->toContain('chown -R panel:panel /opt/fnm/node-versions/v24.1.0');
    });
});

describe('a Node site created without a version', function () {
    beforeEach(function () {
        Queue::fake();
        $this->owner = SystemUser::create([
            'username' => 'nodeowner', 'home_path' => '/home/nodeowner', 'shell' => '/bin/bash', 'sudo' => false,
        ]);
        fakeNode(installed: ['22.11.0', '24.1.0'], default: '22.11.0');
    });

    function createNodeSite(string $type, array $extra = []): Application
    {
        return app(CreateApplication::class)->execute(array_merge([
            'name' => 'qa-'.$type,
            'domain' => $type.'.example.test',
            'domain_type' => 'custom',
            'site_type' => $type,
            'system_user_id' => test()->owner->id,
            'admin_username' => 'admin',
            'admin_password' => 'Secret12345!',
        ], $extra));
    }

    it('is pinned to the server default', function () {
        // Left blank, the installer had no Node on its PATH: a Node-RED on a
        // fresh server died on `npm: No such file or directory`.
        expect(createNodeSite('nodered')->node_version)->toBe('22.11.0');
    });

    it('is pinned to a version the type can run when the default is outside its range', function () {
        // n8n needs 24+. Pinning the 22 default would reproduce the n8n
        // install that died on a Node it cannot run.
        expect(createNodeSite('n8n')->node_version)->toBe('24.1.0');
    });

    it('keeps the version it was asked for', function () {
        expect(createNodeSite('nodered', ['node_version' => '24.1.0'])->node_version)->toBe('24.1.0');
    });
});
