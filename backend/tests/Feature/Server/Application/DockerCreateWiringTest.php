<?php

use App\Models\Application;
use App\Models\Registry;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\HostCpus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Creating a container site together with the network and volume it needs.
 *
 * Reported as "there should be an option to customize the name, and to select
 * which to create along with the container". Before this, a site that wanted both
 * was six steps: create the site, go to the Docker page, create a network, create
 * a volume, come back, attach, mount.
 *
 * The rule that keeps it honest is the INVERSE of the one on the picker: the
 * "create a new one" field refuses a name that already exists. Creating-if-missing
 * and adopting-if-present reads as success while attaching a new site to another
 * site's network, and a typo that happens to collide is exactly the case that must
 * not quietly work.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'shop', 'home_path' => '/home/shop']);
});

/** @param  list<string>  $networks  what `docker network ls` reports. */
function fakeDockerBoxFor(array $networks = [], array $volumes = []): void
{
    Process::fake(function ($process) use ($networks, $volumes) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[1] ?? '') === 'network' && ($args[2] ?? '') === 'ls') {
            return Process::result(output: implode("\n", array_map(
                fn (string $n): string => json_encode(['ID' => 'a', 'Name' => $n, 'Driver' => 'bridge', 'Scope' => 'local']),
                $networks,
            )));
        }

        if (($args[1] ?? '') === 'system' && ($args[2] ?? '') === 'df') {
            return Process::result(output: json_encode(array_map(
                fn (string $n): array => ['Name' => $n, 'Driver' => 'local', 'Mountpoint' => '/x', 'Size' => '0B', 'Links' => '0'],
                $volumes,
            )));
        }

        if (($args[1] ?? '') === 'ps') {
            return Process::result(output: '');
        }

        return Process::result(exitCode: 0);
    });
}

function dockerCreatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'system_user_id' => test()->systemUser->id,
        'site_type' => 'docker',
        'image' => 'ghost:5-alpine',
        'container_port' => 2368,
    ], $overrides);
}

function dockerCreateHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

it('creates a site with a network named on the form', function () {
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['docker_network_new' => 'ghost-net']))
        ->assertCreated();

    // Recorded in the SAME column the picker writes, so nothing downstream has to
    // ask which field it came from.
    expect(Application::where('name', 'Shop')->first()->docker_network)->toBe('ghost-net');
});

it('creates a site with a volume and its path', function () {
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload([
            'volume_new' => 'ghost-content',
            'volume_path' => '/var/lib/ghost/content',
        ]))
        ->assertCreated();

    expect(Application::where('name', 'Shop')->first()->volume_mounts)
        ->toBe([['volume' => 'ghost-content', 'path' => '/var/lib/ghost/content']]);
});

it('refuses a new name that already exists, rather than adopting it', function () {
    // Adopting reads as success and attaches the site to somebody else's network.
    fakeDockerBoxFor(networks: ['ghost-net']);

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['docker_network_new' => 'ghost-net']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('docker_network_new');

    expect(Application::where('name', 'Shop')->exists())->toBeFalse();
});

it('refuses a new volume name that already exists', function () {
    fakeDockerBoxFor(volumes: ['ghost-content']);

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload([
            'volume_new' => 'ghost-content',
            'volume_path' => '/data',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('volume_new');
});

it('refuses picking a network and naming a new one at once', function () {
    // Two answers to one question. Honouring both would mean deciding which the
    // user meant.
    fakeDockerBoxFor(networks: ['existing-net']);

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload([
            'docker_network' => 'existing-net',
            'docker_network_new' => 'another-net',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('docker_network_new');
});

it('refuses half a volume', function () {
    // A name with no path is not something the panel can act on, and a path with
    // no name is not a volume.
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['volume_new' => 'lonely']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('volume_path');

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['volume_path' => '/data']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('volume_new');
});

it('refuses a volume path over the site\'s own files at create time too', function () {
    // The same rule the Container card enforces. A volume over the site mount
    // hides the site's files rather than deleting them, which reads as data
    // loss. `/panel-site` since DS-01 — `/app` is a legal volume path now.
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload([
            'volume_new' => 'ghost-content',
            'volume_path' => '/panel-site/content',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('volume_path');
});

it('still accepts an existing network from the picker', function () {
    // The regression that would matter: gating the new field must not break the
    // old one.
    fakeDockerBoxFor(networks: ['existing-net']);

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['docker_network' => 'existing-net']))
        ->assertCreated();

    expect(Application::where('name', 'Shop')->first()->docker_network)->toBe('existing-net');
});

it('creates the objects on the box before the container starts', function () {
    // `external: true` means Compose looks them up and refuses rather than
    // creating them, so a site created with "make a new network" has nothing on
    // the box at this point.
    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran[] = $args;

        if (($args[1] ?? '') === 'network' && ($args[2] ?? '') === 'ls') {
            return Process::result(output: '');
        }
        if (($args[1] ?? '') === 'system') {
            return Process::result(output: '[]');
        }
        if (($args[1] ?? '') === 'compose') {
            return Process::result(output: "abc\n");
        }

        return Process::result(exitCode: 0);
    });

    $application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id, 'name' => 'Shop', 'slug' => 'shop',
        'domain' => 'shop.test', 'web_root' => 'public_html',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'status' => 'active',
        'image' => 'ghost:5', 'container_port' => 2368, 'app_port' => 20005,
        'docker_network' => 'ghost-net',
        'volume_mounts' => [['volume' => 'ghost-content', 'path' => '/data']],
    ]);

    app(DockerResources::class)->ensureFor($application);

    $index = fn (callable $match): int|false => collect($ran)->search($match);

    expect($index(fn (array $a): bool => ($a[1] ?? '') === 'network' && ($a[2] ?? '') === 'create'))
        ->not->toBeFalse()
        ->and($index(fn (array $a): bool => ($a[1] ?? '') === 'volume' && ($a[2] ?? '') === 'create'))
        ->not->toBeFalse();
});

it('does not recreate objects that are already there', function () {
    // `network create` errors on an existing name and `volume create` silently
    // returns the existing volume — neither is safe to fire blindly, for opposite
    // reasons.
    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran[] = $args;

        if (($args[1] ?? '') === 'network' && ($args[2] ?? '') === 'ls') {
            return Process::result(output: json_encode([
                'ID' => 'a', 'Name' => 'ghost-net', 'Driver' => 'bridge', 'Scope' => 'local',
            ]));
        }
        if (($args[1] ?? '') === 'system') {
            return Process::result(output: json_encode([
                ['Name' => 'ghost-content', 'Driver' => 'local', 'Mountpoint' => '/x', 'Size' => '0B', 'Links' => '0'],
            ]));
        }
        if (($args[1] ?? '') === 'ps') {
            return Process::result(output: '');
        }

        return Process::result(exitCode: 0);
    });

    $application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id, 'name' => 'Shop', 'slug' => 'shop',
        'domain' => 'shop.test', 'web_root' => 'public_html',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'status' => 'active',
        'image' => 'ghost:5', 'container_port' => 2368, 'app_port' => 20006,
        'docker_network' => 'ghost-net',
        'volume_mounts' => [['volume' => 'ghost-content', 'path' => '/data']],
    ]);

    expect(app(DockerResources::class)->ensureFor($application))->toBe([])
        ->and(collect($ran)->contains(fn (array $a): bool => ($a[2] ?? '') === 'create'))->toBeFalse();
});

/*
 * The registry picker, which is the one container field that reaches a row in the
 * panel's own database rather than a Docker object.
 */

it('creates a site with a registry chosen on the form', function () {
    fakeDockerBoxFor();

    $registry = Registry::forceCreate([
        'name' => 'GHCR', 'registry' => 'ghcr.io', 'username' => 'octocat',
        'config' => ['token' => 'ghp_TOKEN'],
    ]);

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['registry_id' => $registry->id]))
        ->assertCreated();

    // The whole point of asserting a create path separately: the field is declared
    // on the site type and the column is fillable, and BOTH have to be true for
    // `typeColumns()` to carry it. A field that is only one of the two vanishes
    // silently, which is how a Docker site once arrived with a null `compose`.
    expect(Application::where('name', 'Shop')->first()->registry_id)->toBe($registry->id);
});

it('creates a site with no registry when the picker was left empty', function () {
    // The default, and the case every public image takes. An empty select posts an
    // empty STRING, not null — if that reached the integer foreign key it would be
    // a constraint violation on the happy path.
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['registry_id' => '']))
        ->assertCreated();

    expect(Application::where('name', 'Shop')->first()->registry_id)->toBeNull();
});

it('refuses a registry that does not exist', function () {
    // Otherwise the site pulls anonymously and fails on the next deploy with a
    // reason that names credentials nobody ever configured.
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['registry_id' => 4242]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('registry_id');
});

/*
 * The size of the container, chosen while deploying it.
 *
 * Both limits were reachable only from the Container screen after the site
 * existed — so the first thing anybody sizing a container went looking for was
 * not on the form that creates one. They are real columns, so declaring them as
 * type fields is all that is needed to persist them (`CreateApplication::
 * typeColumns()` writes any declared field that is fillable); these tests exist
 * because that is an easy thing to half-do and impossible to see.
 */

it('creates a site at the size the form asked for', function () {
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload([
            'memory_limit' => '1g',
            'cpu_limit' => '1.5',
        ]))
        ->assertCreated();

    $application = Application::where('name', 'Shop')->first();

    expect($application->memory_limit)->toBe('1g')
        ->and($application->cpu_limit)->toBe('1.5');
});

it('creates a site with no limits when both were left empty', function () {
    // The important half. An empty CPU field has to stay null rather than become
    // a zero or an empty string: null means no quota, and anything else renders a
    // `cpus` key — which for `0` means *unlimited*, and for `""` is invalid YAML
    // the site would not come up on.
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload())
        ->assertCreated();

    $application = Application::where('name', 'Shop')->first();

    expect($application->memory_limit)->toBeNull()
        ->and($application->cpu_limit)->toBeNull();
});

it('refuses more CPUs than the box has at create time, not at provision time', function () {
    // Docker would refuse it too, at `compose up` — which here means a site that
    // is created, fails to provision, and has to be deleted and made again. The
    // form is where that belongs.
    fakeDockerBoxFor();

    app()->instance(HostCpus::class, new class extends HostCpus
    {
        public function count(): int
        {
            return 2;
        }
    });

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['cpu_limit' => '8']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('cpu_limit');

    expect(Application::where('name', 'Shop')->exists())->toBeFalse();
});

it('refuses a memory limit the container could not start with', function () {
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload(['memory_limit' => '512']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('memory_limit');
});

/*
 * DS-01: a new site's own directory is mounted at /panel-site, not /app, where a
 * quarter of popular images keep their program.
 */

it('creates a site whose directory is mounted away from /app', function () {
    fakeDockerBoxFor();

    $this->withHeaders(dockerCreateHeaders())
        ->postJson('/api/applications', dockerCreatePayload([
            'image' => 'gotify/server:3.1.1',
            'container_port' => 80,
            'volume_new' => 'gotify-data',
            'volume_path' => '/app/data',
        ]))
        ->assertCreated();

    $application = Application::where('name', 'Shop')->first();

    expect($application->site_mount_path)->toBe('/panel-site')
        ->and($application->volume_mounts)->toBe([['volume' => 'gotify-data', 'path' => '/app/data']]);
});
