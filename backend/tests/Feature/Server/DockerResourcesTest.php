<?php

use App\Models\ServerCapability;
use App\Models\User;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Docker's networks and volumes, which are server-level because that is what
 * they are: shared objects that outlive any one container.
 *
 * The refusals are the feature. Docker's own answers are worse than nothing
 * in three specific ways, and each has a test here:
 *
 *  - `network rm` on a network in use says "has active endpoints" and names
 *    neither the network nor what is on it.
 *  - `volume rm` refuses a volume in use, but `-f` does not — and the panel
 *    must never be the thing that deletes a running database.
 *  - `bridge`, `host` and `none` are recreated by Docker on restart, so a
 *    delete is a control that either fails or does damage.
 */

/*
 * Prefixed helpers. Pest loads every test file into one process, so a bare
 * `dockerOps()` shares a global namespace with every other suite —
 * `DockerChecksTest` already owns that name, and the collision is a fatal
 * error rather than a failure, so it takes the whole run down.
 */

/** Answers each `op` from the handler map; records what ran. */
function dockerResourceOps(array $handlers, array &$ran = []): ServerOps
{
    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('run')->andReturnUsing(
        function (array $command, array $context = []) use ($handlers, &$ran) {
            $op = $context['op'] ?? '';
            $ran[] = ['op' => $op, 'command' => $command];

            return isset($handlers[$op])
                ? $handlers[$op]($command)
                : new ServerOpsResult(ok: true, reference: 'r', answered: true);
        }
    );

    return $ops;
}

function dockerResourceOutput(string $stdout): ServerOpsResult
{
    $p = Mockery::mock(ProcessResult::class);
    $p->shouldReceive('output')->andReturn($stdout);
    $p->shouldReceive('errorOutput')->andReturn('');

    return new ServerOpsResult(ok: true, reference: 'r', result: $p, answered: true);
}

/** The real shapes, copied from a Docker box rather than imagined. */
const NETWORK_LINES = '{"ID":"07f16ac819f4","Name":"bridge","Driver":"bridge","Scope":"local","Internal":"false"}'
    ."\n".'{"ID":"fd7aca4f548e","Name":"host","Driver":"host","Scope":"local","Internal":"false"}'
    ."\n".'{"ID":"aa11bb22cc33","Name":"sv-app-6_default","Driver":"bridge","Scope":"local","Internal":"false"}';

const VOLUME_JSON = '[{"Name":"probe-vol","Driver":"local","Mountpoint":"/var/lib/docker/volumes/probe-vol/_data","Size":"5.243MB","Links":"0"},'
    .'{"Name":"sv-app-6_data","Driver":"local","Mountpoint":"/var/lib/docker/volumes/sv-app-6_data/_data","Size":"120MB","Links":"1"}]';

function dockerAdmin(): User
{
    test()->seed(PermissionSeeder::class);

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    return User::factory()->admin()->create();
}

it('reads size from system df, because volume ls reports N/A', function () {
    // The size column is the one people actually want, and `docker volume ls`
    // answers "N/A" for it. `system df -v` carries size AND Links — how many
    // containers use the volume — which makes "in use" answerable without a
    // second call per volume.
    $ran = [];
    $ops = dockerResourceOps(['docker_volume_df' => fn () => dockerResourceOutput(VOLUME_JSON)], $ran);

    $volumes = (new DockerResources($ops))->volumes();

    expect($volumes[0]['size'])->toBe('5.243MB')
        ->and($volumes[0]['in_use'])->toBeFalse()
        ->and($volumes[0]['dangling'])->toBeTrue()
        ->and($volumes[1]['in_use'])->toBeTrue()
        ->and($volumes[1]['containers'])->toBe(1)
        // And it came from df, not from `volume ls`.
        ->and(collect($ran)->pluck('op'))->toContain('docker_volume_df');
});

it('marks Docker-owned networks so the UI cannot offer a delete', function () {
    $ops = dockerResourceOps(['docker_network_ls' => fn () => dockerResourceOutput(NETWORK_LINES)]);

    $networks = collect((new DockerResources($ops))->networks());

    expect($networks->firstWhere('name', 'bridge')['built_in'])->toBeTrue()
        ->and($networks->firstWhere('name', 'host')['built_in'])->toBeTrue()
        ->and($networks->firstWhere('name', 'sv-app-6_default')['built_in'])->toBeFalse();
});

it('says which application a compose-created name belongs to', function () {
    // `sv-app-6_default` is a machine-generated name. Presenting it as though
    // a human chose it leaves the user guessing which site it serves.
    $ops = dockerResourceOps(['docker_network_ls' => fn () => dockerResourceOutput(NETWORK_LINES)]);

    $networks = collect((new DockerResources($ops))->networks());

    expect($networks->firstWhere('name', 'sv-app-6_default')['application_id'])->toBe(6)
        ->and($networks->firstWhere('name', 'bridge')['application_id'])->toBeNull();
});

it('refuses a name that could arrive as a flag', function () {
    // The value reaches a command line. Docker's own rule already excludes
    // whitespace, every shell metacharacter and — the one that matters — a
    // leading dash.
    expect(DockerResources::validName('my-net'))->toBeTrue()
        ->and(DockerResources::validName('my.net_1'))->toBeTrue()
        ->and(DockerResources::validName('-rm'))->toBeFalse()
        ->and(DockerResources::validName('a b'))->toBeFalse()
        ->and(DockerResources::validName('a;rm -rf /'))->toBeFalse()
        ->and(DockerResources::validName(''))->toBeFalse();
});

it('never passes --force when removing a volume', function () {
    // `docker volume rm -f` removes a volume that is still attached, which is
    // how somebody deletes a database while it is running. The panel checks
    // first and refuses; without this the check would be advisory.
    $ran = [];
    (new DockerResources(dockerResourceOps([], $ran)))->removeVolume('probe-vol');

    $command = collect($ran)->firstWhere('op', 'docker_volume_remove')['command'];

    expect($command)->not->toContain('-f')
        ->and($command)->not->toContain('--force');
});

it('creates only bridge networks', function () {
    // `overlay` needs swarm, `macvlan` hands the container an address on the
    // host's LAN, and `host` is what the whole design refuses. A driver
    // dropdown would be three ways to break the box and one that works.
    $ran = [];
    (new DockerResources(dockerResourceOps([], $ran)))->createNetwork('mynet');

    expect(collect($ran)->firstWhere('op', 'docker_network_create')['command'])
        ->toContain('--driver')
        ->toContain('bridge');
});

it('names the containers when refusing to delete a busy network', function () {
    // Docker says "has active endpoints" and names neither the network nor
    // what is on it, which sends the user to a terminal to find out.
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('networks')->andReturn([[
            'name' => 'sv-app-6_default', 'built_in' => false,
            'containers' => ['uptime-kuma-1'], 'application_id' => 6,
        ]]);
    });

    $this->actingAs($admin)
        ->deleteJson('/api/docker/networks/sv-app-6_default')
        ->assertStatus(409)
        ->assertJsonFragment(['message' => __('errors/docker.network_in_use', [
            'name' => 'sv-app-6_default',
            'containers' => 'uptime-kuma-1',
        ])]);
});

it('refuses to delete one of Docker\'s own networks', function () {
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('networks')->andReturn([[
            'name' => 'bridge', 'built_in' => true, 'containers' => [], 'application_id' => null,
        ]]);
    });

    $this->actingAs($admin)->deleteJson('/api/docker/networks/bridge')->assertStatus(422);
});

it('refuses to delete a volume something is writing to', function () {
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('volumes')->andReturn([[
            'name' => 'sv-app-6_data', 'in_use' => true, 'containers' => 1, 'application_id' => 6,
        ]]);
    });

    $this->actingAs($admin)->deleteJson('/api/docker/volumes/sv-app-6_data')->assertStatus(409);
});

it('is not there at all on a server that hosts no containers', function () {
    // The mirror of the databases gate. Without it these endpoints answer on
    // every server, shell out to a docker binary that is not installed, and
    // report its absence as a server error — "Docker is not working" being a
    // bad way to say "this is a LEMP box".
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'serving_profiles' => ['php', 'static']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->actingAs($admin)->getJson('/api/docker/networks')->assertStatus(409);
    $this->actingAs($admin)->getJson('/api/docker/volumes')->assertStatus(409);
});

it('translates every refusal in every locale', function () {
    $keys = [
        'not_a_docker_server', 'invalid_name', 'network_exists', 'volume_exists',
        'network_built_in', 'network_in_use', 'volume_in_use',
        'network_create_failed', 'network_remove_failed',
        'volume_create_failed', 'volume_remove_failed',
    ];

    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        foreach ($keys as $key) {
            expect(__("errors/docker.{$key}", [], $locale))
                ->not->toBe("errors/docker.{$key}", "{$key} missing in {$locale}");
        }
    }
});
