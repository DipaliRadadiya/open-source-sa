<?php

use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Registry;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Rules\ContainerMountPath;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Docker\Images\ImageInspector;
use App\Services\Server\Docker\Images\ImageLookupException;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;

/**
 * DS-03: creating a Docker site from an image takes what the image says.
 *
 * The container port used to default to 80 and be free text, so users typed a
 * server port (8082) and got a 502 behind a panel saying Running. Now an empty
 * port is read from the image's EXPOSE, a typed one that the image does not
 * declare is accepted with a warning, and an image that declares none is
 * refused with a question. Env vars and any number of volumes can be given at
 * create, and the image's VOLUMEs are the default volumes.
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

    $this->systemUser = SystemUser::create(['username' => 'memos', 'home_path' => '/home/memos']);

    config(['server.docker.images.inspect_on_create' => true]);
    Bus::fake([ProvisionApplication::class]);
    fakeDefaultsBox();
});

/** @param  list<string>  $volumes  what the box already has */
function fakeDefaultsBox(array $volumes = []): void
{
    Process::fake(function ($process) use ($volumes) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[1] ?? '') === 'system' && ($args[2] ?? '') === 'df') {
            return Process::result(output: json_encode(array_map(
                fn (string $n): array => ['Name' => $n, 'Driver' => 'local', 'Mountpoint' => '/x', 'Size' => '0B', 'Links' => '0'],
                $volumes,
            )));
        }

        return Process::result(output: '');
    });
}

/** @param  array<string, mixed>|ImageLookupException  $answer */
function imageSays(array|ImageLookupException $answer): void
{
    test()->mock(ImageInspector::class, function ($mock) use ($answer) {
        $expectation = $mock->shouldReceive('inspect');

        $answer instanceof ImageLookupException
            ? $expectation->andThrow($answer)
            : $expectation->andReturn($answer + ['found' => true, 'exposed_ports' => [], 'suggested_port' => null, 'volumes' => []]);
    });
}

function createDocker(array $overrides = [])
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken])
        ->postJson('/api/applications', array_merge([
            'name' => 'Memos',
            'domain' => 'memos.example.com',
            'system_user_id' => test()->systemUser->id,
            'site_type' => 'docker',
            'image' => 'neosmemo/memos:0.31.0',
        ], $overrides));
}

it('takes the container port from the image when none is given', function () {
    imageSays(['exposed_ports' => [5230], 'suggested_port' => 5230]);

    createDocker()->assertCreated()->assertJsonPath('warnings', []);

    expect(Application::firstWhere('name', 'Memos')->container_port)->toBe(5230);
});

it('refuses, with a question, an image that declares no port', function () {
    imageSays(['exposed_ports' => [], 'suggested_port' => null]);

    createDocker()->assertStatus(422)
        ->assertJsonValidationErrors(['container_port' => 'does not say which port']);

    expect(Application::count())->toBe(0);
});

it('never falls back to 80', function () {
    // The default that started this: with inspection off and no port typed,
    // the answer is a question, not port 80.
    config(['server.docker.images.inspect_on_create' => false]);

    createDocker()->assertStatus(422)->assertJsonValidationErrors('container_port');
});

it('says the image could not be found rather than guessing a port', function () {
    imageSays(['found' => false]);

    createDocker()->assertStatus(422)
        ->assertJsonValidationErrors(['container_port' => 'could not be found']);
});

it('asks for the port when the registry cannot be reached', function () {
    imageSays(new ImageLookupException(ImageLookupException::UNREACHABLE));

    createDocker()->assertStatus(422)
        ->assertJsonValidationErrors(['container_port' => 'could not be reached']);
});

it('creates a site whose typed port the image does not declare, and warns', function () {
    imageSays(['exposed_ports' => [5230], 'suggested_port' => 5230]);

    createDocker(['container_port' => 8082])
        ->assertCreated()
        ->assertJsonPath('warnings.0', 'The image listens on 5230, not 8082. The site will not answer unless the application really listens on 8082.');

    expect(Application::firstWhere('name', 'Memos')->container_port)->toBe(8082);
});

it('does not warn when the typed port is one the image declares', function () {
    imageSays(['exposed_ports' => [80, 443], 'suggested_port' => 80]);

    createDocker(['container_port' => 443])->assertCreated()->assertJsonPath('warnings', []);
});

it('creates the site as typed when the registry is down and a port was given', function () {
    imageSays(new ImageLookupException(ImageLookupException::UNREACHABLE));

    createDocker(['container_port' => 5230])->assertCreated()->assertJsonPath('warnings', []);
});

it('keeps env vars encrypted until provisioning, never in settings or the response', function () {
    imageSays(['exposed_ports' => [9090], 'suggested_port' => 9090]);

    $response = createDocker(['env' => [
        ['key' => 'LD_SUPERUSER_NAME', 'value' => 'admin'],
        ['key' => 'LD_SUPERUSER_PASSWORD', 'value' => 's3cret$pass'],
    ]])->assertCreated();

    expect(json_encode($response->json()))->not->toContain('s3cret');

    $application = Application::firstWhere('name', 'Memos');

    expect($application->install_secrets[Application::CONTAINER_ENV_SECRET])->toBe([
        ['LD_SUPERUSER_NAME', 'admin'],
        ['LD_SUPERUSER_PASSWORD', 's3cret$pass'],
    ])
        ->and(json_encode($application->settings))->not->toContain('s3cret')
        ->and((string) $application->getRawOriginal('install_secrets'))->not->toContain('s3cret');
});

it('refuses env keys a shell or Compose would not accept', function (string $key) {
    imageSays(['exposed_ports' => [80], 'suggested_port' => 80]);

    createDocker(['env' => [['key' => $key, 'value' => 'x']]])
        ->assertStatus(422)->assertJsonValidationErrors('env.0.key');
})->with(['1ABC', 'MY-VAR', 'A B', "A\nB", '']);

it('refuses the same env key twice', function () {
    imageSays(['exposed_ports' => [80], 'suggested_port' => 80]);

    createDocker(['env' => [['key' => 'A', 'value' => '1'], ['key' => 'A', 'value' => '2']]])
        ->assertStatus(422)->assertJsonValidationErrors('env.1.key');
});

it('refuses env and volumes alongside a pasted compose file', function () {
    createDocker([
        'image' => null,
        'compose' => "services:\n  app:\n    image: nginx\n",
        'env' => [['key' => 'A', 'value' => '1']],
    ])->assertStatus(422)->assertJsonValidationErrors('env');
});

it('names unnamed volumes after the site and the path, uniquely', function () {
    fakeDefaultsBox(volumes: ['memos-config']);
    imageSays(['exposed_ports' => [8096], 'suggested_port' => 8096]);

    createDocker(['volume_mounts' => [
        ['path' => '/config'],
        ['path' => '/cache/'],
        ['volume' => 'my-media', 'path' => '/media'],
    ]])->assertCreated();

    expect(Application::firstWhere('name', 'Memos')->volume_mounts)->toBe([
        ['volume' => 'memos-config-2', 'path' => '/config'],
        ['volume' => 'memos-cache', 'path' => '/cache'],
        ['volume' => 'my-media', 'path' => '/media'],
    ]);
});

it('mounts the image\'s own VOLUMEs when no volumes were named', function () {
    imageSays(['exposed_ports' => [8096], 'suggested_port' => 8096, 'volumes' => ['/config', '/cache']]);

    createDocker(['image' => 'jellyfin/jellyfin:10.11.0'])->assertCreated();

    expect(Application::firstWhere('name', 'Memos')->volume_mounts)->toBe([
        ['volume' => 'memos-config', 'path' => '/config'],
        ['volume' => 'memos-cache', 'path' => '/cache'],
    ]);
});

it('respects an explicit empty list of volumes', function () {
    imageSays(['exposed_ports' => [8096], 'suggested_port' => 8096, 'volumes' => ['/config']]);

    createDocker(['volume_mounts' => []])->assertCreated();

    expect(Application::firstWhere('name', 'Memos')->volume_mounts)->toBeNull();
});

it('skips an image VOLUME the site mount owns', function () {
    imageSays(['exposed_ports' => [80], 'suggested_port' => 80, 'volumes' => ['/panel-site', '/data']]);

    createDocker()->assertCreated();

    expect(Application::firstWhere('name', 'Memos')->volume_mounts)->toBe([
        ['volume' => 'memos-data', 'path' => '/data'],
    ]);
});

it('refuses a volume path twice', function () {
    imageSays(['exposed_ports' => [80], 'suggested_port' => 80]);

    createDocker(['volume_mounts' => [['path' => '/data'], ['path' => '/data/']]])
        ->assertStatus(422)->assertJsonValidationErrors('volume_mounts.1.path');
});

it('writes create-time env vars to the env file once, then forgets them', function () {
    $application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Ld', 'slug' => 'ld', 'domain' => 'ld.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'sissbruecker/linkding:1.47.0', 'container_port' => 9090, 'app_port' => 3005,
        'status' => 'pending',
        'install_secrets' => [Application::CONTAINER_ENV_SECRET => [['LD_SUPERUSER_NAME', 'admin'], ['LD_SUPERUSER_PASSWORD', "it's \$x"]]],
    ]);

    $written = null;

    Process::fake(function ($process) use (&$written) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (in_array('tee', $args, true) && str_ends_with((string) end($args), '.env.panel-tmp')) {
            $written = $process->input;
        }

        if (in_array('--status', $args, true)) {
            return Process::result(output: "abc\n");
        }

        if (($args[0] ?? '') === 'curl') {
            return Process::result(output: '200');
        }

        return Process::result(output: '');
    });

    $method = new ReflectionMethod(ApplicationProvisioner::class, 'writeContainerEnv');
    $method->invoke(app(ApplicationProvisioner::class), $application);

    expect($written)->toBe("LD_SUPERUSER_NAME='admin'\nLD_SUPERUSER_PASSWORD=\"it's \\\$x\"\n")
        ->and($application->fresh()->install_secrets)->toBeNull();

    // Twice is once: a retry must not overwrite what the user has since edited.
    $written = null;
    $method->invoke(app(ApplicationProvisioner::class), $application->fresh());
    expect($written)->toBeNull();
});

it('quotes env values so Compose reads back exactly what was typed', function (string $value, string $line) {
    expect(ApplicationProvisioner::envLine('K', $value))->toBe($line);
})->with([
    'plain' => ['abc', "K='abc'"],
    'dollar, hash, spaces' => ['a $b #c', "K='a \$b #c'"],
    'single quote' => ["it's", 'K="it\'s"'],
    'multi-line' => ["one\r\ntwo", 'K="one\ntwo"'],
    'backslash and double quote' => ["a\\\"b'", 'K="a\\\\\\"b\'"'],
    'empty' => ['', "K=''"],
]);

/*
 * DS-08: a mount path is written into the generated compose file as YAML, and
 * nothing validates that file after it is rendered. A newline in a path was a
 * new key — the payloads below are the exact ones from the security review.
 */

it('refuses a volume path that would write new keys into the compose file', function (string $path) {
    imageSays(['exposed_ports' => [80], 'suggested_port' => 80]);

    createDocker(['image' => 'alpine:3', 'container_port' => 80, 'volume_mounts' => [['path' => $path]]])
        ->assertStatus(422)->assertJsonValidationErrors('volume_mounts.0.path');

    expect(Application::count())->toBe(0);
})->with([
    'review exploit' => "/data\n      - /:/hostfs:rw\n    privileged: true",
    'carriage return' => "/data\r    privileged: true",
    'yaml comment' => '/data #x',
    'yaml mapping' => '/data: {privileged: true}',
    'space' => '/my data',
]);

it('refuses a trailing newline in the rule itself, where TrimStrings does not reach', function () {
    // A request trims it away; an image's VOLUME and a row written by code do
    // not pass through middleware. PCRE's `$` alone accepts one trailing `\n`.
    $failed = false;
    (new ContainerMountPath)->validate('path', "/data\n", function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeTrue()
        ->and(preg_match(ContainerMountPath::PATTERN, "/data\n"))->toBe(0)
        ->and(preg_match(ContainerMountPath::PATTERN, '/var/lib/my-app_2.0'))->toBe(1);
});

it('refuses the same payload in the single volume field', function () {
    imageSays(['exposed_ports' => [80], 'suggested_port' => 80]);

    createDocker(['container_port' => 80, 'volume_new' => 'memos-data', 'volume_path' => "/data\n    privileged: true"])
        ->assertStatus(422)->assertJsonValidationErrors('volume_path');
});

it('drops an image VOLUME that would write new keys into the compose file', function () {
    // No request field needed: the panel used to insert the registry's text
    // itself. Dropped rather than refused — the user did not type it.
    imageSays(['exposed_ports' => [80], 'suggested_port' => 80, 'volumes' => [
        "/data\n      - /:/hostfs",
        '/config',
    ]]);

    createDocker(['image' => 'myregistry.example/evil:1'])->assertCreated();

    expect(Application::firstWhere('name', 'Memos')->volume_mounts)->toBe([
        ['volume' => 'memos-config', 'path' => '/config'],
    ]);
});

it('refuses a stored registry credential to a user who cannot view registries', function () {
    imageSays(['exposed_ports' => [80], 'suggested_port' => 80]);
    $registry = Registry::forceCreate([
        'name' => 'GHCR', 'registry' => 'ghcr.io', 'username' => 'deploy', 'config' => ['token' => 'x'],
    ]);

    $role = Role::create(['name' => 'Deployer', 'slug' => 'deployer']);
    $role->permissions()->attach(Permission::where('name', 'application')->sole()->id, ['view' => true, 'manage' => true]);
    $user = User::factory()->create();
    $user->roles()->attach($role);

    $as = fn (User $u) => ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
    $body = ['name' => 'Memos', 'domain' => 'memos.example.com', 'system_user_id' => $this->systemUser->id,
        'site_type' => 'docker', 'image' => 'ghcr.io/acme/app:1', 'container_port' => 80];

    $this->withHeaders($as($user))->postJson('/api/applications', $body + ['registry_id' => $registry->id])->assertForbidden();
    expect(Application::count())->toBe(0);

    // The same user may create without one.
    $this->withHeaders($as($user))->postJson('/api/applications', $body)->assertCreated();
});
