<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
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

it('shows which containers are reachable from the host and which are not', function () {
    // The whole security story of this stack, and invisible unless said: a
    // bare `3306/tcp` is exposed to the container network only, while
    // `127.0.0.1:2368->2368/tcp` is published to the host. The arrow is the
    // only difference and it is far too easy to read the two as the same.
    //
    // Real shapes: Ghost and its MySQL, on one network, differing exactly here.
    $ps = '{"Names":"sv-app-7-ghost-1","Ports":"127.0.0.1:2368->2368/tcp"}'
        ."\n".'{"Names":"sv-app-7-db-1","Ports":"3306/tcp, 33060/tcp"}';

    $ops = dockerResourceOps([
        'docker_network_ls' => fn () => dockerResourceOutput('{"ID":"a1","Name":"sv-app-7_default","Driver":"bridge","Scope":"local","Internal":"false"}'),
        'docker_ps_ports' => fn () => dockerResourceOutput($ps),
        'docker_network_inspect' => fn () => dockerResourceOutput(json_encode([
            'abc' => ['Name' => 'sv-app-7-ghost-1'],
            'def' => ['Name' => 'sv-app-7-db-1'],
        ])),
    ]);

    $containers = collect((new DockerResources($ops))->networks())
        ->firstWhere('name', 'sv-app-7_default')['containers'];

    $ghost = collect($containers)->firstWhere('name', 'sv-app-7-ghost-1');
    $db = collect($containers)->firstWhere('name', 'sv-app-7-db-1');

    expect($ghost['published'])->toBeTrue()
        ->and($ghost['ports'])->toContain('127.0.0.1:2368->2368/tcp')
        // Exposed, not published — nothing on the host can reach it.
        ->and($db['published'])->toBeFalse()
        ->and($db['ports'])->toContain('3306/tcp')
        ->and($db['ports'])->toContain('33060/tcp');
});

it('asks docker ps once, not once per network', function () {
    // `network inspect` knows the containers and nothing about their ports;
    // `ps` knows the ports and nothing about the networks. One call each,
    // joined on the name — a per-network `ps` would be a process per row.
    $ran = [];
    $ops = dockerResourceOps([
        'docker_network_ls' => fn () => dockerResourceOutput(NETWORK_LINES),
        'docker_ps_ports' => fn () => dockerResourceOutput('{"Names":"x","Ports":""}'),
    ], $ran);

    (new DockerResources($ops))->networks();

    expect(collect($ran)->where('op', 'docker_ps_ports')->count())->toBe(1);
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

    // The container shape `networks()` really returns — `{name, ports,
    // published}`, not a bare string. This mock said string, and that is the
    // only reason the refusal read correctly here: the real path imploded an
    // array of arrays and shipped "still has containers on it: Array". A fake
    // that has drifted from its subject asserts nothing about the subject.
    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('networks')->andReturn([[
            'name' => 'sv-app-6_default', 'built_in' => false,
            'containers' => [
                ['name' => 'uptime-kuma-1', 'ports' => ['127.0.0.1:3001->3001/tcp'], 'published' => true],
            ],
            'sites' => [], 'application_id' => 6,
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

it('refuses to delete a network a site is set to join', function () {
    // The guard the container check cannot make. A STOPPED site is attached to
    // nothing, so `containers` is empty and every check above passes — and its
    // compose file still names this network with `external: true`, so the
    // delete succeeds and the site never starts again. Nothing would connect
    // that failure to this click.
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('networks')->andReturn([[
            'name' => 'ghost-net', 'built_in' => false, 'containers' => [],
            'sites' => [['id' => 6, 'name' => 'staging-api']], 'application_id' => null,
        ]]);
    });

    $this->actingAs($admin)
        ->deleteJson('/api/docker/networks/ghost-net')
        ->assertStatus(409)
        // Named, not counted: "it is in use" sends someone to the terminal.
        ->assertJsonFragment(['message' => __('errors/docker.network_used_by_sites', [
            'name' => 'ghost-net',
            'sites' => 'staging-api',
        ])]);
});

it('deletes a network no site names and nothing is attached to', function () {
    // The other half of the guard above. Without this, "refuses when
    // referenced" could be satisfied by refusing always.
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('networks')->andReturn([[
            'name' => 'spare-net', 'built_in' => false, 'containers' => [],
            'sites' => [], 'application_id' => null,
        ]]);
        $mock->shouldReceive('removeNetwork')->once()->with('spare-net')
            ->andReturn(new ServerOpsResult(ok: true, reference: 'r', answered: true));
    });

    $this->actingAs($admin)->deleteJson('/api/docker/networks/spare-net')->assertOk();
});

it('reports which sites name a network', function () {
    // `application_id` infers ownership from Compose's `sv-app-<id>_default`
    // naming, which says nothing about a site that CHOSE a network somebody
    // else created. Two different questions, and the page needs both.
    $user = SystemUser::create(['username' => 'ghost', 'home_path' => '/home/ghost']);
    $application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Ghost', 'slug' => 'ghost',
        'domain' => 'ghost.test', 'web_root' => 'public_html',
        'site_type' => 'docker', 'serving_profile' => 'docker',
        'image' => 'ghost:5', 'container_port' => 2368, 'app_port' => 20001,
        'docker_network' => 'ghost-net',
    ]);

    $networks = (new DockerResources(dockerResourceOps([
        'docker_network_ls' => fn () => dockerResourceOutput(
            '{"ID":"a1","Name":"ghost-net","Driver":"bridge","Scope":"local","Internal":"false"}'
        ),
    ])))->networks();

    expect($networks[0]['sites'])->toBe([['id' => $application->id, 'name' => 'Ghost']])
        // A site that chose the network is not a site that Compose named after
        // it, and the page needs to tell those apart.
        ->and($networks[0]['application_id'])->toBeNull();
});

it('refuses to delete a volume something is writing to', function () {
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('volumes')->andReturn([[
            'name' => 'sv-app-6_data', 'in_use' => true, 'containers' => 1,
            'container_names' => ['sv-app-6-db-1'], 'sites' => [], 'application_id' => 6,
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

/*
 * Which containers use a volume.
 *
 * "1 container(s)" is a number, and what somebody needs is the name of the thing
 * to go and stop. The source has to be `container inspect`: see the test below
 * about truncation for why `docker ps` cannot answer this.
 */

/** `docker container inspect --format '{{json .}}'` over two containers. */
const INSPECT_JSON = '{"Name":"/sv-app-6-db-1","Mounts":[{"Type":"volume","Name":"sv-app-6_data"}]}'
    ."\n".'{"Name":"/sv-app-6-worker-1","Mounts":[{"Type":"volume","Name":"sv-app-6_data"},'
    .'{"Type":"bind","Source":"/home/six/six/public_html","Destination":"/app"}]}';

it('names the containers using a volume', function () {
    $volumes = collect((new DockerResources(dockerResourceOps([
        'docker_volume_df' => fn () => dockerResourceOutput(VOLUME_JSON),
        'docker_ps_ids' => fn () => dockerResourceOutput("abc123\ndef456\n"),
        'docker_container_inspect' => fn () => dockerResourceOutput(INSPECT_JSON),
    ])))->volumes());

    expect($volumes->firstWhere('name', 'sv-app-6_data')['container_names'])
        ->toBe(['sv-app-6-db-1', 'sv-app-6-worker-1'])
        // And a volume nothing touches stays empty rather than inheriting a name.
        ->and($volumes->firstWhere('name', 'probe-vol')['container_names'])->toBe([]);
});

it('does not count a bind mount as a volume user', function () {
    // `sv-app-6-worker-1` above also bind-mounts its site directory. A site that
    // mounts a host path is not a user of any volume, and listing it as one puts
    // a name in the Used-by column of a volume it has nothing to do with.
    $volumes = collect((new DockerResources(dockerResourceOps([
        'docker_volume_df' => fn () => dockerResourceOutput(
            '[{"Name":"public_html","Driver":"local","Mountpoint":"/x","Size":"0B","Links":"0"}]'
        ),
        'docker_ps_ids' => fn () => dockerResourceOutput("def456\n"),
        'docker_container_inspect' => fn () => dockerResourceOutput(INSPECT_JSON),
    ])))->volumes());

    expect($volumes->firstWhere('name', 'public_html')['container_names'])->toBe([]);
});

it('asks inspect once, not once per volume', function () {
    // Same rule `docker ps` follows for networks. A `--filter volume=` per row
    // would be a process per row, and the volumes list is unbounded.
    $ran = [];
    (new DockerResources(dockerResourceOps([
        'docker_volume_df' => fn () => dockerResourceOutput(VOLUME_JSON),
        'docker_ps_ids' => fn () => dockerResourceOutput("abc123\n"),
        'docker_container_inspect' => fn () => dockerResourceOutput(INSPECT_JSON),
    ], $ran)))->volumes();

    expect(collect($ran)->where('op', 'docker_container_inspect')->count())->toBe(1);
});

it('does not run inspect at all when there are no containers', function () {
    // `docker container inspect` with no arguments is an ERROR — "requires at
    // least 1 argument" — not an empty answer. Unguarded, every load of the
    // volumes page on an idle box wrote a failed op to the log.
    $ran = [];
    $volumes = (new DockerResources(dockerResourceOps([
        'docker_volume_df' => fn () => dockerResourceOutput(VOLUME_JSON),
        'docker_ps_ids' => fn () => dockerResourceOutput("\n"),
    ], $ran)))->volumes();

    expect(collect($ran)->where('op', 'docker_container_inspect')->count())->toBe(0)
        ->and(collect($volumes)->pluck('container_names')->flatten()->all())->toBe([]);
});

it('would match nothing if the names came from docker ps', function () {
    // THE REASON `volumeContainers()` uses inspect. `docker ps --format`
    // truncates the mount list: on a real box two different volumes both came
    // back as `sv-app-7_ghost…`, ellipsis included. This is that string, and it
    // must not match — a join on a truncated name either finds nothing or, with
    // two volumes sharing a prefix, finds the wrong one.
    $volumes = collect((new DockerResources(dockerResourceOps([
        'docker_volume_df' => fn () => dockerResourceOutput(VOLUME_JSON),
        'docker_ps_ids' => fn () => dockerResourceOutput("abc123\n"),
        'docker_container_inspect' => fn () => dockerResourceOutput(
            '{"Name":"/sv-app-6-db-1","Mounts":[{"Type":"volume","Name":"sv-app-6_dat…"}]}'
        ),
    ])))->volumes());

    expect($volumes->firstWhere('name', 'sv-app-6_data')['container_names'])->toBe([]);
});

it('keeps refusing a volume in use even when it cannot name the containers', function () {
    // `in_use` reads `Links` from `system df -v`, not the names. If inspect did
    // not answer, the volume still has users and the delete must still be
    // refused — falling back to the count rather than deleting data because the
    // panel failed to name what is writing it.
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('volumes')->andReturn([[
            'name' => 'sv-app-6_data', 'in_use' => true, 'containers' => 2,
            'container_names' => [], 'sites' => [], 'application_id' => 6,
        ]]);
    });

    $this->actingAs($admin)
        ->deleteJson('/api/docker/volumes/sv-app-6_data')
        ->assertStatus(409)
        ->assertJsonFragment(['message' => __('errors/docker.volume_in_use', [
            'name' => 'sv-app-6_data', 'count' => '2',
        ])]);
});

it('names the containers when refusing to delete a volume', function () {
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('volumes')->andReturn([[
            'name' => 'sv-app-6_data', 'in_use' => true, 'containers' => 2,
            'container_names' => ['sv-app-6-db-1', 'sv-app-6-worker-1'],
            'sites' => [], 'application_id' => 6,
        ]]);
    });

    $this->actingAs($admin)
        ->deleteJson('/api/docker/volumes/sv-app-6_data')
        ->assertStatus(409)
        ->assertJsonFragment(['message' => __('errors/docker.volume_in_use_by', [
            'name' => 'sv-app-6_data',
            'containers' => 'sv-app-6-db-1, sv-app-6-worker-1',
        ])]);
});

/*
 * Deleting a volume a site mounts.
 *
 * The most consequential guard on this screen. `Links` counts CONTAINERS, so a
 * stopped site has none and every other check passes — while the volume still
 * holds that site's database. Deleting a network out from under a stopped site
 * breaks a start; deleting its volume destroys the data.
 */

it('reports which sites mount a volume', function () {
    $user = SystemUser::create(['username' => 'shop', 'home_path' => '/home/shop']);
    $application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Shop', 'slug' => 'shop',
        'domain' => 'shop.test', 'web_root' => 'public_html',
        'site_type' => 'docker', 'serving_profile' => 'docker',
        'image' => 'mysql:8', 'container_port' => 3306, 'app_port' => 20002,
        'volume_mounts' => [
            ['volume' => 'shop-db', 'path' => '/var/lib/mysql'],
            // Same volume twice: the site must be listed once, not twice.
            ['volume' => 'shop-db', 'path' => '/backup'],
        ],
    ]);

    $volumes = collect((new DockerResources(dockerResourceOps([
        'docker_volume_df' => fn () => dockerResourceOutput(
            '[{"Name":"shop-db","Driver":"local","Mountpoint":"/x","Size":"1MB","Links":"0"}]'
        ),
        'docker_ps_ids' => fn () => dockerResourceOutput("\n"),
    ])))->volumes());

    expect($volumes->firstWhere('name', 'shop-db')['sites'])
        ->toBe([['id' => $application->id, 'name' => 'Shop']]);
});

it('refuses to delete a volume a stopped site still mounts', function () {
    // `Links` is 0 and `container_names` is empty — every earlier check passes.
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('volumes')->andReturn([[
            'name' => 'shop-db', 'in_use' => false, 'containers' => 0,
            'container_names' => [], 'sites' => [['id' => 4, 'name' => 'Shop']],
            'application_id' => null,
        ]]);
    });

    $this->actingAs($admin)
        ->deleteJson('/api/docker/volumes/shop-db')
        ->assertStatus(409)
        ->assertJsonFragment(['message' => __('errors/docker.volume_used_by_sites', [
            'name' => 'shop-db', 'sites' => 'Shop',
        ])]);
});

it('deletes a volume no site mounts and nothing is using', function () {
    // Without this, "refuses when mounted" could be satisfied by refusing always.
    $admin = dockerAdmin();

    $this->mock(DockerResources::class, function ($mock) {
        $mock->shouldReceive('volumes')->andReturn([[
            'name' => 'spare-vol', 'in_use' => false, 'containers' => 0,
            'container_names' => [], 'sites' => [], 'application_id' => null,
        ]]);
        $mock->shouldReceive('removeVolume')->once()->with('spare-vol')
            ->andReturn(new ServerOpsResult(ok: true, reference: 'r', answered: true));
    });

    $this->actingAs($admin)->deleteJson('/api/docker/volumes/spare-vol')->assertOk();
});
