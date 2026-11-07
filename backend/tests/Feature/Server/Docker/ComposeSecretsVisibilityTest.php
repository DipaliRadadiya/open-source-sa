<?php

use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ComposeSecrets;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * A recipe's compose file has its generated passwords written into it. Reading
 * the file is `app_compose` view; reading the passwords is `app_container`
 * manage on their own, logged endpoint. The file handed them to a view-only
 * user anyway (live-reproduced 2026-11-06 on a real BookStack install), so the
 * values are now masked for anyone who could not read them there, and a masked
 * file cannot be saved back over the real one.
 */

const COMPOSE_TEST_DB_SECRET = 'k3Vq8ZtLr2WmXp9a';
const COMPOSE_TEST_ROOT_SECRET = 'Rt5Hn7Qe1YuBc4Js6Dw';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $systemUser = SystemUser::create(['username' => 'books', 'home_path' => '/home/books']);

    $this->compose = "services:\n  app:\n    image: lscr.io/linuxserver/bookstack\n    environment:\n"
        .'      DB_PASSWORD: '.COMPOSE_TEST_DB_SECRET."\n    ports:\n      - \"127.0.0.1:20001:80\"\n"
        ."  db:\n    image: mariadb:11\n    environment:\n"
        .'      MARIADB_PASSWORD: '.COMPOSE_TEST_DB_SECRET."\n"
        .'      MARIADB_ROOT_PASSWORD: '.COMPOSE_TEST_ROOT_SECRET."\n";

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Books', 'slug' => 'books', 'domain' => 'books.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'lscr.io/linuxserver/bookstack', 'container_port' => 80, 'app_port' => 20001,
        'status' => 'active',
        'compose' => $this->compose,
        'docker_secrets' => ['db_password' => COMPOSE_TEST_DB_SECRET, 'root_password' => COMPOSE_TEST_ROOT_SECRET, 'short' => 'app'],
    ]);

    Process::fake();
});

/**
 * A user whose role holds exactly these grants.
 *
 * @param  array<string, array{view: bool, manage: bool}>  $grants
 */
function composeUser(array $grants): array
{
    $role = Role::create(['name' => 'R'.uniqid(), 'slug' => 'r'.uniqid()]);

    foreach ($grants as $name => $abilities) {
        $role->permissions()->attach(Permission::where('name', $name)->sole()->id, $abilities);
    }

    $user = User::factory()->create();
    $user->roles()->attach($role);

    return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
}

function composeFileUrl(): string
{
    return '/api/applications/'.test()->application->id.'/container/compose';
}

it('masks the passwords for a user who may read the file but not the passwords', function () {
    $headers = composeUser([
        'application' => ['view' => true, 'manage' => false],
        'app_compose' => ['view' => true, 'manage' => false],
    ]);

    $response = $this->withHeaders($headers)->getJson(composeFileUrl())->assertOk();

    expect($response->json('redacted'))->toBeTrue()
        ->and($response->getContent())->not->toContain(COMPOSE_TEST_DB_SECRET)
        ->and($response->getContent())->not->toContain(COMPOSE_TEST_ROOT_SECRET)
        ->and($response->json('compose'))->toContain('DB_PASSWORD: '.ComposeSecrets::PLACEHOLDER)
        ->and($response->json('compose'))->toContain('MARIADB_ROOT_PASSWORD: '.ComposeSecrets::PLACEHOLDER)
        // A short secret is not masked by text, so it cannot mangle the image name.
        ->and($response->json('compose'))->toContain('image: lscr.io/linuxserver/bookstack');

    // The passwords' own endpoint still refuses this user, as before.
    $this->withHeaders($headers)
        ->getJson('/api/applications/'.$this->application->id.'/container/secrets')
        ->assertForbidden();
});

it('shows the real file to a user who may read the passwords anyway', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
        ->getJson(composeFileUrl())
        ->assertOk();

    expect($response->json('redacted'))->toBeFalse()
        ->and($response->json('compose'))->toBe($this->compose);
});

it('says nothing was masked for a file with no generated passwords in it', function () {
    $this->application->forceFill(['docker_secrets' => null])->save();

    $headers = composeUser([
        'application' => ['view' => true, 'manage' => false],
        'app_compose' => ['view' => true, 'manage' => false],
    ]);

    $this->withHeaders($headers)->getJson(composeFileUrl())
        ->assertOk()
        ->assertJsonPath('redacted', false)
        ->assertJsonPath('compose', $this->compose);
});

it('refuses to save a masked file back over the real one', function () {
    $headers = composeUser([
        'application' => ['view' => true, 'manage' => false],
        'app_compose' => ['view' => true, 'manage' => true],
    ]);

    $masked = $this->withHeaders($headers)->getJson(composeFileUrl())->json('compose');

    $this->withHeaders($headers)->putJson(composeFileUrl(), ['compose' => $masked])
        ->assertStatus(422)
        ->assertJsonPath('errors.compose.0', __('errors/application.compose_redacted'));

    expect($this->application->fresh()->compose)->toBe($this->compose);
    Process::assertNothingRan();
});

it('masks a secret that contains another one whole', function () {
    [$masked, $redacted] = ComposeSecrets::redact(
        "A: abcdefgh12345678\nB: abcdefgh\n",
        ['inner' => 'abcdefgh', 'outer' => 'abcdefgh12345678'],
    );

    expect($redacted)->toBeTrue()
        ->and($masked)->toBe('A: '.ComposeSecrets::PLACEHOLDER."\nB: ".ComposeSecrets::PLACEHOLDER."\n");
});

it('has the refusal translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        expect(__('errors/application.compose_redacted', [], $locale))->not->toBe('errors/application.compose_redacted');
    }
});
