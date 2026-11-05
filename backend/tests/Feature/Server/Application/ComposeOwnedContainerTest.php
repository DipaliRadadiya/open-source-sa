<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\HostCpus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/**
 * `PUT /applications/{id}/container` for a site that runs its own compose file
 * (RC-31): a 1-Click Recipe app, or a file taken over on the Compose File page.
 *
 * The port, network and volume fields of that form never reach such a site — the
 * supervisor renders the stored file and ignores them — so accepting them answered
 * "saved" and changed nothing. They are refused instead, when the value differs
 * from the one the site already has. The limits and the registry still apply, and a
 * normal Docker site is untouched.
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
});

const OWN_COMPOSE = "services:\n  app:\n    image: ghost:5\n    ports:\n      - \"127.0.0.1:20001:2368\"\n";

/**
 * @param  'recipe'|'taken-over'|'plain'  $kind
 * @param  array<string, mixed>  $overrides
 */
function ownApp(string $kind, array $overrides = []): Application
{
    $shared = [
        'system_user_id' => test()->systemUser->id,
        'name' => 'Ghost',
        'slug' => 'ghost',
        'domain' => 'ghost.test',
        'serving_profile' => 'docker',
        'status' => 'active',
        'web_root' => 'public_html',
        'container_port' => 2368,
        'app_port' => 20001,
    ];

    $row = match ($kind) {
        // What `DockerAppInstaller` leaves: the recipe's slug as the site type and
        // the rendered file in the column.
        'recipe' => [
            'site_type' => 'ghost',
            'image' => 'ghost:5-alpine',
            'compose' => OWN_COMPOSE,
            'volume_mounts' => [['volume' => 'sv-app-1_content', 'path' => '/var/lib/ghost/content']],
        ],
        // A simple site whose generated file was edited: the text wins from then on.
        'taken-over' => [
            'site_type' => 'docker',
            'image' => 'ghost:5',
            'compose' => OWN_COMPOSE,
            'docker_network' => 'ghost-net',
            'volume_mounts' => [
                ['volume' => 'ghost-data', 'path' => '/data'],
                ['volume' => 'ghost-cache', 'path' => '/cache'],
            ],
        ],
        'plain' => [
            'site_type' => 'docker',
            'image' => 'ghost:5',
            'docker_network' => 'ghost-net',
            'volume_mounts' => [['volume' => 'ghost-data', 'path' => '/data']],
        ],
    };

    return test()->application = Application::forceCreate([...$shared, ...$row, ...$overrides]);
}

function ownRan(): ArrayObject
{
    static $bag = null;

    return $bag ??= new ArrayObject;
}

/**
 * A box that answers the way a docker host does for the three lookups the form's
 * rules make, and reads the stored file back as one published loopback port.
 *
 * @param  list<string>  $networks
 * @param  list<string>  $volumes
 */
function ownFakeBox(array $networks = ['ghost-net', 'other-net'], array $volumes = ['ghost-data', 'ghost-cache', 'extra']): void
{
    ownRan()->exchangeArray([]);

    $resolved = json_encode([
        'name' => 'sv-app-1',
        'services' => ['app' => [
            'image' => 'ghost:5',
            'ports' => [['mode' => 'ingress', 'host_ip' => '127.0.0.1', 'target' => 2368, 'published' => '20001', 'protocol' => 'tcp']],
        ]],
    ]);

    Process::fake(function ($process) use ($networks, $volumes, $resolved) {
        $args = $process->command;

        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }

        ownRan()->append($args);

        if (($args[0] ?? '') === 'docker' && in_array('config', $args, true)) {
            return Process::result(output: $resolved);
        }

        if (($args[1] ?? '') === 'network' && ($args[2] ?? '') === 'ls') {
            return Process::result(output: implode("\n", array_map(
                fn (string $name): string => json_encode([
                    'ID' => substr(md5($name), 0, 12), 'Name' => $name,
                    'Driver' => 'bridge', 'Scope' => 'local', 'Internal' => 'false',
                ]),
                $networks,
            )));
        }

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

        if (($args[0] ?? '') === 'docker' && in_array('ps', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });
}

function ownPut(array $payload): TestResponse
{
    return test()
        ->withHeaders(['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken])
        ->putJson('/api/applications/'.test()->application->id.'/container', $payload);
}

/** Did `compose up` run — the half of a save a column assertion cannot see. */
function ownComposeUpRan(): bool
{
    foreach (ownRan() as $args) {
        if (($args[1] ?? '') === 'compose' && in_array('up', $args, true)) {
            return true;
        }
    }

    return false;
}

function ownRefusal(): string
{
    return __('errors/application.container_fields_owned_by_compose');
}

function ownWithCpus(int $cores): void
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

/** @return array<string, mixed> the three compose-owned columns as they stand. */
function ownColumns(): array
{
    return test()->application->fresh()->only(['container_port', 'docker_network', 'volume_mounts']);
}

const OWN_KINDS = ['recipe', 'taken-over'];

it('refuses a changed container port and runs nothing', function (string $kind, mixed $port) {
    ownApp($kind);
    ownFakeBox();
    $before = ownColumns();

    ownPut(['container_port' => $port])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('container_port')
        ->assertJsonPath('errors.container_port.0', ownRefusal());

    expect(ownColumns())->toBe($before)
        ->and(count(ownRan()))->toBe(0);
})->with(OWN_KINDS)->with([8080, '8080', 'abc', null]);

it('refuses a changed network and runs nothing', function (string $kind, ?string $network) {
    ownApp($kind);
    ownFakeBox();
    $before = ownColumns();

    ownPut(['docker_network' => $network])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('docker_network')
        ->assertJsonPath('errors.docker_network.0', ownRefusal());

    expect(ownColumns())->toBe($before)
        ->and(count(ownRan()))->toBe(0);
})->with([
    // The recipe app sits on no named network; the taken-over one on `ghost-net`.
    'recipe moved to another network' => ['recipe', 'other-net'],
    'recipe put on the network the taken-over site has' => ['recipe', 'ghost-net'],
    'taken-over moved to another network' => ['taken-over', 'other-net'],
    'taken-over taken off its network' => ['taken-over', null],
    'taken-over given an empty-string network' => ['taken-over', ''],
]);

it('refuses changed volume mounts and runs nothing', function (string $kind, array $mounts) {
    ownApp($kind);
    ownFakeBox();
    $before = ownColumns();

    ownPut(['volume_mounts' => $mounts])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('volume_mounts')
        ->assertJsonPath('errors.volume_mounts.0', ownRefusal());

    expect(ownColumns())->toBe($before)
        ->and(count(ownRan()))->toBe(0);
})->with(OWN_KINDS)->with([
    'a mount added' => [[['volume' => 'extra', 'path' => '/extra']]],
    'every mount cleared' => [[]],
    'a mount moved to another path' => [[['volume' => 'ghost-data', 'path' => '/elsewhere']]],
    'not a list of pairs' => [[['volume' => 'ghost-data']]],
]);

it('names every field it refuses', function (string $kind) {
    ownApp($kind);
    ownFakeBox();

    ownPut([
        'container_port' => 8080,
        'docker_network' => 'other-net',
        'volume_mounts' => [['volume' => 'extra', 'path' => '/extra']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['container_port', 'docker_network', 'volume_mounts']);

    expect(count(ownRan()))->toBe(0);
})->with(OWN_KINDS);

it('refuses the whole request when a limit travels with a changed port, saving nothing', function (string $kind) {
    ownApp($kind);
    ownFakeBox();
    $before = test()->application->fresh()->memory_limit;

    ownPut(['memory_limit' => '1g', 'container_port' => 8080])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('container_port');

    expect(test()->application->fresh()->memory_limit)->toBe($before)
        ->and(count(ownRan()))->toBe(0);
})->with(OWN_KINDS);

it('accepts the values the site already has, in any order, and leaves them alone', function (string $kind) {
    $app = ownApp($kind);
    ownFakeBox();
    $before = ownColumns();

    ownPut([
        'container_port' => '2368',
        'docker_network' => $app->docker_network,
        'volume_mounts' => array_reverse($app->volume_mounts),
        'memory_limit' => '768m',
    ])->assertOk()->assertJsonPath('application.memory_limit', '768m');

    expect(ownColumns())->toBe($before)
        ->and(test()->application->fresh()->memory_limit)->toBe('768m')
        ->and(ownComposeUpRan())->toBeTrue();

    // Nothing was looked up for the three fields the file owns: they were not
    // validated, because nothing of them is applied.
    $lookups = collect(ownRan())->filter(fn (array $args): bool => in_array(($args[1] ?? ''), ['network', 'system'], true));
    expect($lookups)->toHaveCount(0);
})->with(OWN_KINDS);

it('accepts an echo of nothing at all, for a site with no network or mounts', function () {
    ownApp('recipe', ['volume_mounts' => null, 'docker_network' => null]);
    ownFakeBox();

    ownPut(['docker_network' => null, 'volume_mounts' => null, 'container_port' => 2368])->assertOk();
    ownPut(['docker_network' => '', 'volume_mounts' => []])->assertOk();
});

it('still saves the limits and the registry of a site on its own file', function (string $kind) {
    ownApp($kind);
    ownFakeBox();
    ownWithCpus(4);

    ownPut(['memory_limit' => '1g', 'cpu_limit' => '1.5'])
        ->assertOk()
        ->assertJsonPath('application.memory_limit', '1g')
        ->assertJsonPath('application.cpu_limit', '1.5');

    expect(test()->application->fresh()->only(['memory_limit', 'cpu_limit']))->toBe(['memory_limit' => '1g', 'cpu_limit' => '1.5'])
        ->and(ownComposeUpRan())->toBeTrue();

    // And the limits are still checked.
    ownPut(['cpu_limit' => '16'])->assertUnprocessable()->assertJsonValidationErrors('cpu_limit');
    ownPut(['memory_limit' => 'lots'])->assertUnprocessable()->assertJsonValidationErrors('memory_limit');
})->with(OWN_KINDS);

it('leaves a normal Docker site as it was: port, network and mounts still change', function () {
    ownApp('plain');
    ownFakeBox();

    ownPut([
        'container_port' => 8080,
        'docker_network' => 'other-net',
        'volume_mounts' => [['volume' => 'extra', 'path' => '/extra']],
    ])->assertOk();

    expect(ownColumns())->toBe([
        'container_port' => 8080,
        'docker_network' => 'other-net',
        'volume_mounts' => [['volume' => 'extra', 'path' => '/extra']],
    ])->and(ownComposeUpRan())->toBeTrue();
});

it('still validates the fields of a normal Docker site', function () {
    ownApp('plain');
    ownFakeBox();

    ownPut(['docker_network' => 'nowhere'])->assertUnprocessable()->assertJsonValidationErrors('docker_network');
    ownPut(['volume_mounts' => [['volume' => 'missing', 'path' => '/x']]])->assertUnprocessable()->assertJsonValidationErrors('volume_mounts.0.volume');
    ownPut(['container_port' => 0])->assertUnprocessable()->assertJsonValidationErrors('container_port');
});

it('does not treat a blank compose column as an own file', function (?string $blank) {
    ownApp('plain', ['compose' => $blank]);
    ownFakeBox();

    ownPut(['container_port' => 8080])->assertOk();

    expect(test()->application->fresh()->container_port)->toBe(8080);
})->with([null, '', " \n\t "]);

it('says it in every language', function (string $locale) {
    $key = 'errors/application.container_fields_owned_by_compose';
    $text = __($key, [], $locale);

    expect($text)->not->toBe($key)->and($text)->not->toBeEmpty();

    if ($locale !== 'en') {
        expect($text)->not->toBe(__($key, [], 'en'));
    }
})->with(['de', 'en', 'es', 'fr', 'hi', 'ja', 'pt', 'ru']);
