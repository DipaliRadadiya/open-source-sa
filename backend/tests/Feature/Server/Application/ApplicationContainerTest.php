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
 * The container settings endpoint — and specifically, whether saving one
 * changes the box.
 *
 * These values exist only as inputs to the compose file. A save that writes the
 * row and stops leaves the panel showing a network the container is not on,
 * until some unrelated deploy happens to rewrite the file. That is the failure
 * these cover: not "did the column change" but "did the compose file change and
 * did compose run".
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

function containerRecorder(): ArrayObject
{
    static $bag = null;

    return $bag ??= new ArrayObject;
}

/**
 * Faked per command, and statefully for `network ls`.
 *
 * A blanket `Process::fake()` would answer `docker network ls` with empty
 * output, so the validation rule would report every network missing and the
 * test would be asserting against a box the fake broke.
 *
 * @param  list<string>  $networks  what `docker network ls` reports.
 */
function fakeDockerBox(array $networks = ['ghost-net']): void
{
    containerRecorder()->exchangeArray([]);

    Process::fake(function ($process) use ($networks) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        containerRecorder()->append($args);

        if (($args[1] ?? '') === 'network' && ($args[2] ?? '') === 'ls') {
            return Process::result(output: implode("\n", array_map(
                fn (string $name): string => json_encode([
                    'ID' => substr(md5($name), 0, 12), 'Name' => $name,
                    'Driver' => 'bridge', 'Scope' => 'local', 'Internal' => 'false',
                ]),
                $networks,
            )));
        }

        // `compose ps -q` is how the supervisor decides the container came up.
        // Empty output there reads as "exited" and throws.
        if (($args[1] ?? '') === 'compose') {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });
}

function containerUrl(): string
{
    return '/api/applications/'.test()->application->id.'/container';
}

function containerHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/** Did a command run with these arguments, ignoring any `sudo` wrapper? */
function dockerRan(callable $matches): bool
{
    foreach (containerRecorder() as $args) {
        if ($matches($args)) {
            return true;
        }
    }

    return false;
}

it('saves the network and brings the container up on it', function () {
    fakeDockerBox();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertOk()
        ->assertJsonPath('application.docker_network', 'ghost-net');

    expect($this->application->fresh()->docker_network)->toBe('ghost-net');

    // The half that a column assertion cannot see: a save that did not run
    // compose is a panel showing a network the container is not on.
    expect(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose' && in_array('up', $args, true)))
        ->toBeTrue();
});

it('refuses a network that is not on this server', function () {
    // `external: true` makes Compose look the name up, so a name that is not
    // there is a container that will not start — and the refusal has to arrive
    // on the field, not on the next deploy.
    fakeDockerBox(['ghost-net']);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'not-there'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('docker_network');

    expect($this->application->fresh()->docker_network)->toBeNull();
    expect(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose'))->toBeFalse();
});

it('refuses a network name that could not be one', function () {
    fakeDockerBox();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => '-rm --volumes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('docker_network');

    // Refused on shape alone: a hostile value must not reach `docker`.
    expect(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'network'))->toBeFalse();
});

it('clears the network when asked, and rewrites the file', function () {
    // Null is a real answer — Docker's default bridge — not a missing one.
    fakeDockerBox();
    $this->application->forceFill(['docker_network' => 'ghost-net'])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => null])
        ->assertOk();

    expect($this->application->fresh()->docker_network)->toBeNull()
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose' && in_array('up', $args, true)))
        ->toBeTrue();
});

it('is refused for a site that is not a container', function () {
    // The fields have no meaning for a PHP site, and storing them would look
    // exactly like a feature that works.
    fakeDockerBox();
    $this->application->forceFill(['site_type' => 'php', 'serving_profile' => 'php'])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertStatus(422);

    expect($this->application->fresh()->docker_network)->toBeNull();
});

it('stores without touching the box when the site is not provisioned', function () {
    // Nothing on disk to rewrite for a pending site; running compose against
    // one would be provisioning it as a side effect of saving a form.
    fakeDockerBox();
    $this->application->forceFill(['status' => 'pending'])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertOk();

    expect($this->application->fresh()->docker_network)->toBe('ghost-net')
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose'))->toBeFalse();
});

it('does not resurrect a disabled site', function () {
    // A disabled site's vhost deliberately points at the disabled page.
    // Bringing its container up here would put it back online.
    fakeDockerBox();
    $this->application->forceFill(['disabled_at' => now()])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertOk();

    expect(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose'))->toBeFalse();
});

it('is refused without the manage permission', function () {
    fakeDockerBox();

    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'viewer']));

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertForbidden();

    expect($this->application->fresh()->docker_network)->toBeNull();
});

/*
 * Mounting a volume into the site.
 *
 * The validation here is not paperwork. `/app` is where the generated compose
 * bind-mounts the site's own directory, and a volume over it hides those files
 * from the container while leaving them on disk and in the backup — which looks
 * exactly like deletion and invites a restore that changes nothing.
 */

/** Extends the box fake with a volume list. */
function fakeDockerBoxWithVolumes(array $volumes = ['shop-db']): void
{
    containerRecorder()->exchangeArray([]);

    Process::fake(function ($process) use ($volumes) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        containerRecorder()->append($args);

        if (($args[1] ?? '') === 'system' && ($args[2] ?? '') === 'df') {
            return Process::result(output: json_encode(array_map(
                fn (string $name): array => [
                    'Name' => $name, 'Driver' => 'local',
                    'Mountpoint' => "/var/lib/docker/volumes/{$name}/_data",
                    'Size' => '10MB', 'Links' => '0',
                ],
                $volumes,
            )));
        }

        if (($args[1] ?? '') === 'ps' && in_array('-aq', $args, true)) {
            return Process::result(output: '');
        }

        if (($args[1] ?? '') === 'compose') {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });
}

it('mounts a volume and recreates the container', function () {
    fakeDockerBoxWithVolumes();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => [
            ['volume' => 'shop-db', 'path' => '/var/lib/mysql'],
        ]])
        ->assertOk()
        ->assertJsonPath('application.volume_mounts.0.volume', 'shop-db');

    expect($this->application->fresh()->volume_mounts)
        ->toBe([['volume' => 'shop-db', 'path' => '/var/lib/mysql']])
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose' && in_array('up', $args, true)))
        ->toBeTrue();
});

it('refuses a mount over the site\'s own files', function () {
    // The dangerous one. The files stay on the server and in the backup, and the
    // container serves an empty volume — indistinguishable from deletion.
    fakeDockerBoxWithVolumes();

    foreach (['/app', '/app/', '/app/public', '/app/storage/uploads'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_mounts.0.path');
    }

    expect($this->application->fresh()->volume_mounts)->toBeNull();
});

it('refuses a mount over the image itself', function () {
    fakeDockerBoxWithVolumes();

    foreach (['/', '/etc', '/usr', '/bin', '/lib'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_mounts.0.path');
    }
});

it('refuses a relative path and a traversal', function () {
    fakeDockerBoxWithVolumes();

    foreach (['var/lib/mysql', '/var/../etc', '/var/lib/..'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_mounts.0.path');
    }
});

it('refuses two volumes at the same path', function () {
    // Docker keeps one and discards the other without saying which, so the site
    // would be missing a volume it is configured to have.
    fakeDockerBoxWithVolumes(['shop-db', 'shop-other']);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => [
            ['volume' => 'shop-db', 'path' => '/data'],
            ['volume' => 'shop-other', 'path' => '/data/'],
        ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('volume_mounts.1.path');
});

it('allows one volume at two different paths', function () {
    // Legal Docker, and the top-level declaration is keyed so it appears once.
    fakeDockerBoxWithVolumes(['shared']);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => [
            ['volume' => 'shared', 'path' => '/one'],
            ['volume' => 'shared', 'path' => '/two'],
        ]])
        ->assertOk();

    expect($this->application->fresh()->volume_mounts)->toHaveCount(2);
});

it('refuses a volume that is not on this server', function () {
    fakeDockerBoxWithVolumes(['shop-db']);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => [
            ['volume' => 'not-there', 'path' => '/data'],
        ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('volume_mounts.0.volume');
});

it('clears every mount when given an empty list', function () {
    fakeDockerBoxWithVolumes();
    $this->application->forceFill(['volume_mounts' => [['volume' => 'shop-db', 'path' => '/data']]])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => []])
        ->assertOk();

    expect($this->application->fresh()->volume_mounts)->toBe([]);
});

/*
 * The generated credentials.
 *
 * These are live database passwords. The shape of the feature is the security
 * argument: they are not on the application payload, asking for them is recorded,
 * and the endpoint is gated on `manage` rather than `view` because reading a
 * password is not a read-only act in any sense that matters.
 */

it('does not put the credentials in the application payload', function () {
    // The whole reason for a separate endpoint. On the resource they would ride in
    // every application response, every list, and every cache in between.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'supersecretvalue']])->save();

    // The APPLICATION payload is what must not carry it. The secrets endpoint
    // obviously does — that is its job, and asserting otherwise was this test
    // being wrong rather than the code.
    $body = $this->withHeaders(containerHeaders())
        ->getJson('/api/applications/'.$this->application->id)
        ->assertOk()
        ->content();

    expect($body)->not->toContain('supersecretvalue')
        // But the NAMES are there, so the UI knows what to offer without asking.
        ->and($body)->toContain('MYSQL_ROOT_PASSWORD');
});

it('returns the credentials from their own endpoint', function () {
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => [
        'MYSQL_ROOT_PASSWORD' => 'rootvalue', 'GHOST_DB_PASSWORD' => 'appvalue',
    ]])->save();

    $this->withHeaders(containerHeaders())
        ->getJson(containerUrl2())
        ->assertOk()
        ->assertJsonPath('secrets.MYSQL_ROOT_PASSWORD', 'rootvalue')
        ->assertJsonPath('secrets.GHOST_DB_PASSWORD', 'appvalue');
});

it('records who looked', function () {
    // An audit trail is most of the value: a password that can be read without a
    // trace is a password nobody can reason about after an incident.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'v']])->save();

    $this->withHeaders(containerHeaders())->getJson(containerUrl2())->assertOk();

    expect(ActivityLog::where('action', 'container_secrets_viewed')->exists())
        ->toBeTrue('nothing recorded the read');
});

it('refuses a user without manage', function () {
    // `manage`, not `view`. Somebody who may look at a site must not thereby be
    // able to read its database password.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'v']])->save();

    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'viewer']));

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->getJson(containerUrl2())
        ->assertForbidden();
});

it('refuses a site that is not a container', function () {
    fakeDockerBox();
    $this->application->forceFill(['site_type' => 'php', 'serving_profile' => 'php'])->save();

    $this->withHeaders(containerHeaders())->getJson(containerUrl2())->assertStatus(422);
});

it('answers with an empty set rather than failing when there are none', function () {
    // A plain Docker site has no generated credentials, and that is not an error.
    fakeDockerBox();

    $this->withHeaders(containerHeaders())
        ->getJson(containerUrl2())
        ->assertOk()
        ->assertExactJson(['secrets' => []]);
});

function containerUrl2(): string
{
    return '/api/applications/'.test()->application->id.'/container/secrets';
}
