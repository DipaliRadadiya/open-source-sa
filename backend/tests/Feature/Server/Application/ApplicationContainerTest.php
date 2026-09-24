<?php

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
