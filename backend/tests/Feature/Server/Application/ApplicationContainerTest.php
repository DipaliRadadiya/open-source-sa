<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Registry;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\HostCpus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The container settings endpoint: saving a setting must change the box, not just the row.
 *
 * These values only feed the compose file, so the tests check that compose ran, not the column.
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
 * A blanket `Process::fake()` returns an empty `network ls`, so every network would look missing.
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

    // A column assertion cannot see this: the save must also run compose.
    expect(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose' && in_array('up', $args, true)))
        ->toBeTrue();
});

it('refuses an empty container port rather than rendering port 80 (DS-09)', function () {
    // A null port used to render as `127.0.0.1:{app_port}:80`, which nothing listens on (502).
    fakeDockerBox();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['container_port' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors('container_port');

    expect($this->application->fresh()->container_port)->toBe(2368)
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose'))->toBeFalse();
});

it('renders no compose file for a site with no container port, rather than one on port 80 (DS-12)', function () {
    // Create and PUT refuse a missing port; this is the second guard. A `?: 80` fallback would
    // proxy a port nothing in the image listens on.
    fakeDockerBox();
    $this->application->forceFill(['container_port' => null])->save();

    $e = null;

    try {
        app(ContainerSupervisor::class)->apply($this->application, '/home/ghost/ghost/public_html');
    } catch (ProvisioningFailedException $caught) {
        $e = $caught;
    }

    expect($e?->reason)->toBe('container_port_missing')
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose'))->toBeFalse();

    // The compose editor says why instead of a 500.
    $this->withHeaders(containerHeaders())
        ->getJson('/api/applications/'.$this->application->id.'/container/compose')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'container_port_missing')
        ->assertJsonPath('message', __('application.failure_reason.container_port_missing'));
});

it('lets only someone who may view registries point a site at another credential (DS-12)', function () {
    // Only create was gated, so a stored credential's id was still an oracle through update.
    fakeDockerBox();
    $credential = fn (string $name): Registry => Registry::forceCreate([
        'name' => $name, 'registry' => 'ghcr.io', 'username' => 'deploy', 'config' => ['token' => 'x'],
    ]);
    $current = $credential('Current');
    $other = $credential('Other');
    $this->application->forceFill(['registry_id' => $current->id])->save();

    $role = Role::create(['name' => 'Deployer', 'slug' => 'deployer']);
    foreach (['application', 'app_container'] as $permission) {
        $role->permissions()->attach(Permission::where('name', $permission)->sole()->id, ['view' => true, 'manage' => true]);
    }
    $deployer = User::factory()->create();
    $deployer->roles()->attach($role);
    $as = ['Authorization' => 'Bearer '.$deployer->createToken('t')->plainTextToken];

    $this->withHeaders($as)->putJson(containerUrl(), ['registry_id' => $other->id])->assertForbidden();
    expect($this->application->fresh()->registry_id)->toBe($current->id);

    // The form sends the current one back on every save: no new power.
    $this->withHeaders($as)->putJson(containerUrl(), ['registry_id' => $current->id, 'container_port' => 2368])->assertOk();
    // Nor is pulling anonymously.
    $this->withHeaders($as)->putJson(containerUrl(), ['registry_id' => null])->assertOk();

    // Someone who may view registries may choose one. The guard caches the first user, so reset it.
    app('auth')->forgetGuards();
    $this->withHeaders(containerHeaders())->putJson(containerUrl(), ['registry_id' => $other->id])->assertOk();
    expect($this->application->fresh()->registry_id)->toBe($other->id);
});

it('refuses a network that is not on this server', function () {
    // `external: true` makes Compose look the name up, so an unknown name is a container that will
    // not start; refuse it on the field, not on the next deploy.
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
    // The fields mean nothing for a PHP site; storing them would look like a feature that works.
    // 404, not 422: `CheckPermission` hides an `app_*` screen the site type does not support,
    // before the controller's own 422 check is reached.
    fakeDockerBox();
    $this->application->forceFill(['site_type' => 'php', 'serving_profile' => 'php'])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertStatus(404);

    expect($this->application->fresh()->docker_network)->toBeNull();
});

it('stores without touching the box when the site is not provisioned', function () {
    // Nothing on disk to rewrite for a pending site; running compose would provision it.
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
 * `/app` is where the generated compose bind-mounts the site's own directory. A volume over it
 * hides those files from the container while they stay on disk and in the backup, which looks
 * like deletion.
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
    // The dangerous one: the files stay on disk but the container serves an empty volume.
    fakeDockerBoxWithVolumes();

    foreach (['/app', '/app/', '/app/public', '/app/storage/uploads'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_mounts.0.path');
    }

    expect($this->application->fresh()->volume_mounts)->toBeNull();
});

it('lets a site created since the fix keep data under /app', function () {
    // Gotify's `/app/data`. With its own `site_mount_path`, `/app` is free for the image's data.
    fakeDockerBoxWithVolumes();
    $this->application->forceFill(['site_mount_path' => '/panel-site'])->save();

    foreach (['/app', '/app/data'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertOk()
            ->assertJsonPath('application.site_mount_path', '/panel-site');
    }
});

it('refuses a mount over a new site\'s own files, at their new path', function () {
    fakeDockerBoxWithVolumes();
    $this->application->forceFill(['site_mount_path' => '/panel-site'])->save();

    foreach (['/panel-site', '/panel-site/', '/panel-site/uploads', '/etc', '/'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_mounts.0.path');
    }

    expect($this->application->fresh()->volume_mounts)->toBeNull();
});

it('names the site mount of an existing site as /app', function () {
    // Null in the column, `/app` in the answer — what its compose file says.
    $this->withHeaders(containerHeaders())
        ->getJson('/api/applications/'.$this->application->id)
        ->assertOk()
        ->assertJsonPath('application.site_mount_path', '/app');
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
    // Docker silently keeps one of the two, so the site would lack a configured volume.
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
 * These are live database passwords: kept off the application payload, reads are logged,
 * and the endpoint needs `manage`, not `view`.
 */

it('does not put the credentials in the application payload', function () {
    // The reason for a separate endpoint: on the resource they would ride in every response and cache.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'supersecretvalue']])->save();

    // Only the application payload must not carry it; the secrets endpoint does.
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
    // A password that can be read without a trace cannot be audited after an incident.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'v']])->save();

    $this->withHeaders(containerHeaders())->getJson(containerUrl2())->assertOk();

    expect(ActivityLog::where('action', 'container_secrets_viewed')->exists())
        ->toBeTrue('nothing recorded the read');
});

it('refuses a user without manage', function () {
    // `manage`, not `view`: viewing a site must not expose its database password.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'v']])->save();

    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'viewer']));

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->getJson(containerUrl2())
        ->assertForbidden();
});

it('refuses a site that is not a container', function () {
    // 404 rather than 422 — see the note on the settings endpoint above.
    fakeDockerBox();
    $this->application->forceFill(['site_type' => 'php', 'serving_profile' => 'php'])->save();

    $this->withHeaders(containerHeaders())->getJson(containerUrl2())->assertStatus(404);
});

it('answers with an empty set rather than failing when there are none', function () {
    // A plain Docker site has no generated credentials, and that is not an error.
    fakeDockerBox();

    $this->withHeaders(containerHeaders())
        ->getJson(containerUrl2())
        ->assertOk()
        ->assertExactJson(['secrets' => [], 'credentials' => null]);
});

function containerUrl2(): string
{
    return '/api/applications/'.test()->application->id.'/container/secrets';
}

/*
 * The CPU quota must be refused at the form.
 *
 * Docker only refuses an over-provisioned quota at `up`. Without the rule the field would save
 * the row, fail the apply, and leave the panel showing a limit the container does not have.
 */

/** A box with a known number of CPUs, so the bound is not the runner's. */
function withCpus(int $cores): void
{
    app()->instance(HostCpus::class, new class($cores) extends HostCpus
    {
        public function __construct(private int $cores) {}

        public function count(): int
        {
            return $this->cores;
        }
    });
}

it('saves a cpu quota and applies it to the container', function () {
    fakeDockerBox();
    withCpus(4);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => '1.5'])
        ->assertOk()
        ->assertJsonPath('application.cpu_limit', '1.5');

    expect($this->application->fresh()->cpu_limit)->toBe('1.5')
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose' && in_array('up', $args, true)))
        ->toBeTrue();
});

it('refuses more CPUs than the server has, and saves nothing', function () {
    fakeDockerBox();
    withCpus(2);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => '8'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cpu_limit');

    expect($this->application->fresh()->cpu_limit)->toBeNull();
});

it('names what the server has rather than saying invalid', function () {
    // Why this is a rule and not `numeric|max:`: "must not be greater than 2" does not say what
    // the box has.
    fakeDockerBox();
    withCpus(2);

    $message = $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => '8'])
        ->assertStatus(422)
        ->json('errors.cpu_limit.0');

    expect($message)->toContain('2 CPUs');
});

it('refuses a quota below the one Docker accepts', function () {
    // `cpus: 0` is Docker's spelling of "no limit", so a typed zero would remove limits. Floor: 0.01.
    fakeDockerBox();
    withCpus(4);

    foreach (['0', '0.001', '-1', 'one', '1.555'] as $bad) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['cpu_limit' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpu_limit');
    }

    expect($this->application->fresh()->cpu_limit)->toBeNull();
});

it('clears the quota when the field is emptied', function () {
    // Null means "no limit" and must stay reachable, or a limit could only go by deleting the site.
    fakeDockerBox();
    withCpus(4);
    $this->application->forceFill(['cpu_limit' => '2'])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => null])
        ->assertOk();

    expect($this->application->fresh()->cpu_limit)->toBeNull();
});

it('refuses a memory ceiling Docker would not start the container with', function () {
    // 6MB is Docker's floor. A bare number is bytes, not megabytes, and would not start.
    fakeDockerBox();

    foreach (['2m', '512', '512MB'] as $bad) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['memory_limit' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('memory_limit');
    }
});

it('records the size in the activity log', function () {
    // The log is where a site that was made smaller is told apart from one that was always slow.
    fakeDockerBox();
    withCpus(4);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => '1', 'memory_limit' => '1g'])
        ->assertOk();

    $log = ActivityLog::where('type', 'application')->where('action', 'container_updated')->sole();

    expect($log->properties['cpu_limit'])->toBe('1')
        ->and($log->properties['memory_limit'])->toBe('1g');
});

it('tells the form how big the server is', function () {
    // Lets the field state the ceiling. Not on `/basic-info`: that is unauthenticated.
    fakeDockerBox();
    withCpus(6);

    $this->withHeaders(containerHeaders())
        ->getJson('/api/docker/limits')
        ->assertOk()
        ->assertJsonPath('limits.cpus', 6)
        ->assertJsonPath('limits.default_memory_limit', (string) config('server.docker.default_memory_limit'));
});

/*
 * "I have saved these."
 *
 * A one-click app's admin password is generated by the panel; one never read is an account
 * nobody can sign into. Acknowledging is its own endpoint, not a side effect of reading: the
 * values cannot be rotated from the panel (compose file AND the running database would change),
 * so a card that vanished on render would lose the password to a page refresh.
 */

it('records that somebody saved the credentials', function () {
    fakeDockerBox();

    expect($this->application->credentials_seen_at)->toBeNull();

    $this->withHeaders(containerHeaders())
        ->postJson(containerUrl().'/secrets/acknowledge')
        ->assertOk()
        ->assertJsonPath('application.credentials_acknowledged', true);

    expect($this->application->fresh()->credentials_seen_at)->not->toBeNull();
});

it('does not move the date when acknowledged a second time', function () {
    // Overwriting it on later clicks would erase when the credentials were first seen.
    fakeDockerBox();

    $this->withHeaders(containerHeaders())->postJson(containerUrl().'/secrets/acknowledge')->assertOk();
    $first = $this->application->fresh()->credentials_seen_at;

    $this->travel(2)->minutes();

    $this->withHeaders(containerHeaders())->postJson(containerUrl().'/secrets/acknowledge')->assertOk();

    expect($this->application->fresh()->credentials_seen_at->timestamp)->toBe($first->timestamp);
});

it('does not acknowledge as a side effect of reading the credentials', function () {
    // If rendering the card marked it seen, a refresh mid-copy would hide the password for good.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['ADMIN_PASSWORD' => 'hunter2']])->save();

    $this->withHeaders(containerHeaders())->getJson(containerUrl().'/secrets')->assertOk();

    expect($this->application->fresh()->credentials_seen_at)->toBeNull();
});

it('refuses to acknowledge without the manage permission', function () {
    // Same gate as reading them: only `manage` may see the values.
    fakeDockerBox();

    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'ack-viewer']));

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson(containerUrl().'/secrets/acknowledge')
        ->assertForbidden();

    expect($this->application->fresh()->credentials_seen_at)->toBeNull();
});

it('refuses to acknowledge for a site that is not a container', function () {
    fakeDockerBox();
    $this->application->forceFill(['serving_profile' => 'php', 'site_type' => 'wordpress'])->save();

    $this->withHeaders(containerHeaders())
        ->postJson(containerUrl().'/secrets/acknowledge')
        ->assertStatus(404);
});

it('says a site has unacknowledged credentials in its payload', function () {
    // What puts the card on the dashboard. The flag is about the person: credentials rendered but
    // never confirmed still answer false.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['ADMIN_PASSWORD' => 'hunter2']])->save();

    $payload = $this->withHeaders(containerHeaders())
        ->getJson('/api/applications/'.$this->application->id)
        ->assertOk();

    expect($payload->json('application.credentials_acknowledged'))->toBeFalse()
        ->and($payload->json('application.container_secret_keys'))->toBe(['ADMIN_PASSWORD'])
        // And never the value itself.
        ->and(json_encode($payload->json()))->not->toContain('hunter2');
});

it('refuses a mount path that would write new keys into the compose file (DS-08)', function (string $path) {
    // Injected YAML via `app_container` manage could make the container privileged with `/` mounted.
    fakeDockerBoxWithVolumes();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('volume_mounts.0.path');

    expect($this->application->fresh()->volume_mounts)->toBeNull();
})->with([
    'review exploit' => "/data\n      - /:/hostfs:rw\n    privileged: true",
    'carriage return' => "/data\r    privileged: true",
    'yaml comment' => '/data #x',
]);
