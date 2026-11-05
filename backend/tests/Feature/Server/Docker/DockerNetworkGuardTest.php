<?php

use App\Models\Application;
use App\Models\DockerDatabase;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ComposeValidator;
use App\Services\Server\Docker\NetworkIsolation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Which networks a container may be put on (RC-28).
 *
 * Every field that names a network asked only whether it exists, and `host`,
 * `none` and another site's private network all exist. So a site could be moved
 * onto the server's own network, or next to another site's database. These pin
 * the refusal on each way in — the Container card, the create form, a Docker
 * database, a new network's name, a pasted compose file — and that what a site
 * legitimately does (its own network, a network made to share, the one it is on)
 * still works.
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

    $this->systemUser = SystemUser::create(['username' => 'ghost', 'home_path' => '/home/ghost']);

    $site = fn (string $slug, int $port): Application => Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => ucfirst($slug), 'slug' => $slug, 'domain' => "{$slug}.test",
        'site_type' => 'docker', 'serving_profile' => 'docker', 'status' => 'active',
        'web_root' => 'public_html', 'image' => 'ghost:5', 'container_port' => 2368, 'app_port' => $port,
    ]);

    $this->application = $site('ghost', 20001);
    $this->other = $site('shop', 20002);

    $this->ownNetwork = "sv-app-{$this->application->id}_default";
    $this->otherNetwork = "sv-app-{$this->other->id}_default";
});

function netGuardRan(): ArrayObject
{
    static $bag = null;

    return $bag ??= new ArrayObject;
}

/**
 * Every network the guard has an opinion on is on the box, so a refusal can only
 * come from the guard — never from the existence check after it.
 */
function netGuardBox(): void
{
    netGuardRan()->exchangeArray([]);

    $networks = array_merge(NetworkIsolation::UNJOINABLE, [
        test()->ownNetwork, test()->otherNetwork, 'team-net',
    ]);

    Process::fake(function ($process) use ($networks) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        netGuardRan()->append($args);

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
        if (($args[1] ?? '') === 'compose') {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });
}

/** Did any `docker network …` or `docker compose …` run? */
function netGuardTouchedDocker(): bool
{
    foreach (netGuardRan() as $args) {
        if (($args[0] ?? '') === 'docker' && in_array($args[1] ?? '', ['network', 'compose'], true)) {
            return true;
        }
    }

    return false;
}

function netGuardHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

function netGuardContainerUrl(): string
{
    return '/api/applications/'.test()->application->id.'/container';
}

/*
 * The Container card: PUT …/container.
 */

it('refuses each of Docker\'s own networks, without asking docker or writing anything', function (string $network) {
    netGuardBox();

    $this->withHeaders(netGuardHeaders())
        ->putJson(netGuardContainerUrl(), ['docker_network' => $network])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['docker_network' => __('errors/docker.network_docker_own', ['name' => $network])]);

    expect($this->application->fresh()->docker_network)->toBeNull()
        ->and(netGuardTouchedDocker())->toBeFalse();
})->with(['host', 'none', 'bridge', 'default', 'ingress', 'docker_gwbridge']);

it('refuses another application\'s private network', function () {
    netGuardBox();

    $this->withHeaders(netGuardHeaders())
        ->putJson(netGuardContainerUrl(), ['docker_network' => $this->otherNetwork])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['docker_network' => __('errors/docker.network_other_application', ['name' => $this->otherNetwork])]);

    expect($this->application->fresh()->docker_network)->toBeNull()
        ->and(netGuardTouchedDocker())->toBeFalse();
});

it('says why in plain words', function () {
    // The message is the user's only way to know what to do instead.
    netGuardBox();

    $host = $this->withHeaders(netGuardHeaders())
        ->putJson(netGuardContainerUrl(), ['docker_network' => 'host'])
        ->json('errors.docker_network.0');

    $other = $this->withHeaders(netGuardHeaders())
        ->putJson(netGuardContainerUrl(), ['docker_network' => $this->otherNetwork])
        ->json('errors.docker_network.0');

    expect($host)->toBe("host is one of Docker's own networks and cannot be used for an application. host would put it straight on the server's own network, and the others leave it unable to reach its other containers. Pick a network made on the Docker screen, or keep the application on its own network.")
        ->and($other)->toBe("{$this->otherNetwork} is another application's private network. Joining it would give access to that application's containers, its database included. To connect the two, create a shared network on the Docker screen and put both on it.");
});

it('lets a site move back onto its own network', function () {
    netGuardBox();
    $this->application->forceFill(['docker_network' => 'team-net'])->save();

    $this->withHeaders(netGuardHeaders())
        ->putJson(netGuardContainerUrl(), ['docker_network' => $this->ownNetwork])
        ->assertOk()
        ->assertJsonPath('application.docker_network', $this->ownNetwork);
});

it('lets a site move onto a network a user made to share', function () {
    netGuardBox();

    $this->withHeaders(netGuardHeaders())
        ->putJson(netGuardContainerUrl(), ['docker_network' => 'team-net'])
        ->assertOk()
        ->assertJsonPath('application.docker_network', 'team-net');

    expect($this->application->fresh()->docker_network)->toBe('team-net');
});

it('lets a site stay on the network it is already on', function () {
    // The card sends the current network back with every save. A site put on
    // another one's network before this rule existed can still change its
    // memory limit — the rule refuses moves, not what is already there.
    netGuardBox();
    $this->application->forceFill(['docker_network' => $this->otherNetwork])->save();

    $this->withHeaders(netGuardHeaders())
        ->putJson(netGuardContainerUrl(), ['docker_network' => $this->otherNetwork, 'memory_limit' => '1g'])
        ->assertOk();

    expect($this->application->fresh()->memory_limit)->toBe('1g');
});

/*
 * The create form.
 */

it('refuses Docker\'s own and other sites\' networks when creating a site', function (string $network) {
    netGuardBox();

    $this->withHeaders(netGuardHeaders())
        ->postJson('/api/applications', [
            'name' => 'New', 'domain' => 'new.example.com', 'system_user_id' => $this->systemUser->id,
            'site_type' => 'docker', 'image' => 'ghost:5-alpine', 'container_port' => 2368,
            'docker_network' => $network === 'other' ? $this->otherNetwork : $network,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('docker_network');

    expect(Application::where('name', 'New')->exists())->toBeFalse();
})->with(['host', 'none', 'other']);

it('offers only networks a new site may join', function () {
    netGuardBox();

    $docker = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', 'docker');
    $options = collect(collect($docker['fields'])->firstWhere('name', 'docker_network')['options'])->pluck('value')->all();

    expect($options)->toBe(['team-net']);
});

it('refuses a reserved name for a network made while creating a site', function (string $name) {
    netGuardBox();

    $this->withHeaders(netGuardHeaders())
        ->postJson('/api/applications', [
            'name' => 'New', 'domain' => 'new.example.com', 'system_user_id' => $this->systemUser->id,
            'site_type' => 'docker', 'image' => 'ghost:5-alpine', 'container_port' => 2368,
            'docker_network_new' => $name,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['docker_network_new' => __('errors/docker.network_name_reserved', ['name' => $name])]);
})->with(['sv-app-999_default', 'ingress']);

/*
 * Elsewhere a network is named.
 */

it('refuses Docker\'s own and sites\' private networks for a database', function (string $network) {
    netGuardBox();

    $this->withHeaders(netGuardHeaders())
        ->postJson('/api/docker/databases', [
            'name' => 'shopdb', 'engine' => 'postgres', 'version' => '17',
            'docker_network' => $network === 'other' ? $this->otherNetwork : $network,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('docker_network');

    expect(DockerDatabase::count())->toBe(0);
})->with(['host', 'bridge', 'other']);

it('accepts a shared network for a database', function () {
    // Refused on the engine, so nothing is created — the point is only that the
    // network field itself passes.
    netGuardBox();

    $this->withHeaders(netGuardHeaders())
        ->postJson('/api/docker/databases', [
            'name' => 'shopdb', 'engine' => 'nope', 'version' => '17', 'docker_network' => 'team-net',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('engine')
        ->assertJsonMissingValidationErrors('docker_network');
});

it('refuses to create a network in the sv-app- namespace', function () {
    netGuardBox();

    $this->withHeaders(netGuardHeaders())
        ->postJson('/api/docker/networks', ['name' => 'sv-app-999_default'])
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/docker.network_name_reserved', ['name' => 'sv-app-999_default']));

    expect(collect(netGuardRan())->contains(fn (array $args): bool => ($args[2] ?? '') === 'create'))->toBeFalse();
});

/*
 * A pasted compose file: `external: true` is the other way onto a network.
 */

function netGuardResolved(array $networks): string
{
    return json_encode([
        'name' => 'public_html',
        'services' => ['app' => [
            'image' => 'nginx:1.27-alpine',
            'ports' => [['mode' => 'ingress', 'host_ip' => '127.0.0.1', 'target' => 80, 'published' => '20001', 'protocol' => 'tcp']],
        ]],
        'networks' => $networks,
    ]);
}

it('refuses a pasted compose file that joins Docker\'s own or another site\'s network', function (string $network) {
    // Not serving, so an accepted file would be stored without touching the box —
    // the refusal is the only thing that can keep it out.
    $this->application->forceFill(['status' => 'pending'])->save();
    $name = $network === 'other' ? $this->otherNetwork : $network;

    Process::fake(fn () => Process::result(output: netGuardResolved([
        'default' => ['name' => 'public_html_default'],
        'outside' => ['name' => $name, 'external' => true],
    ])));

    $this->withHeaders(netGuardHeaders())
        ->putJson('/api/applications/'.$this->application->id.'/container/compose', [
            'compose' => "services:\n  app:\n    image: nginx:1.27-alpine\n",
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('compose');

    expect($this->application->fresh()->compose)->toBeNull();
})->with(['host', 'other']);

it('accepts a pasted compose file on its own and a shared network', function () {
    $this->application->forceFill(['status' => 'pending'])->save();
    $compose = "services:\n  app:\n    image: nginx:1.27-alpine\n";

    Process::fake(fn () => Process::result(output: netGuardResolved([
        'default' => ['name' => 'public_html_default'],
        'own' => ['name' => $this->ownNetwork, 'external' => true],
        'shared' => ['name' => 'team-net', 'external' => true],
    ])));

    $this->withHeaders(netGuardHeaders())
        ->putJson('/api/applications/'.$this->application->id.'/container/compose', ['compose' => $compose])
        ->assertOk();

    expect($this->application->fresh()->compose)->toBe($compose);
});

it('treats every site\'s network as foreign to a file for a site not created yet', function () {
    $resolved = json_decode(netGuardResolved(['x' => ['name' => $this->ownNetwork, 'external' => true]]), true);

    expect(app(ComposeValidator::class)->unjoinableNetwork($resolved))
        ->toBe(__('errors/docker.network_other_application', ['name' => $this->ownNetwork]))
        ->and(app(ComposeValidator::class)->unjoinableNetwork($resolved, $this->application->id))->toBeNull();
});
