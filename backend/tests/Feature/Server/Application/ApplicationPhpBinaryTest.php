<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/*
| `php_binary` on an application: the PHP its cron jobs should run (support
| list, 2026-10-28). The cron templates say `{php}`, and the frontend puts
| this in its place — so a site on 8.3 gets 8.3, not the server default.
*/

function phpBinarySite(array $attributes = []): Application
{
    $owner = SystemUser::firstOrCreate(['username' => 'binowner'], ['home_path' => '/home/binowner']);

    return Application::forceCreate([
        'system_user_id' => $owner->id, 'name' => 'Shop '.uniqid(), 'slug' => 'shop-'.uniqid(), 'domain' => uniqid().'.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'php_version' => '8.3',
        ...$attributes,
    ]);
}

function phpBinaryOf(Application $application): ?string
{
    return test()->getJson("/api/applications/{$application->id}")->assertOk()->json('application.php_binary');
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->admin()->create());
});

it('names the site\'s own PHP binary on nginx and Apache', function () {
    config(['server.php_binary_pattern' => '/usr/bin/php{version}']);

    expect(phpBinaryOf(phpBinarySite()))->toBe('/usr/bin/php8.3')
        ->and(phpBinaryOf(phpBinarySite(['php_version' => '8.4'])))->toBe('/usr/bin/php8.4');
});

it('names the LiteSpeed binary on OpenLiteSpeed', function () {
    // What the installer records on an OpenLiteSpeed box.
    ServerCapability::create(['stack' => 'ols', 'web_server' => 'openlitespeed', 'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now()]);

    expect(phpBinaryOf(phpBinarySite()))->toEndWith('/lsphp83/bin/php');
});

it('has none for a site that serves no PHP', function (array $attributes) {
    expect(phpBinaryOf(phpBinarySite($attributes)))->toBeNull();
})->with([
    'node site' => [['site_type' => 'nodered', 'serving_profile' => 'node', 'php_version' => null]],
    'static git site' => [['site_type' => 'git', 'serving_profile' => 'static']],
    'container' => [['site_type' => 'ghost', 'serving_profile' => 'docker', 'php_version' => null]],
]);

it('is in the application list the cron screen reads', function () {
    config(['server.php_binary_pattern' => '/usr/bin/php{version}']);
    $site = phpBinarySite();

    $this->getJson('/api/applications?per_page=100')
        ->assertOk()
        ->assertJsonPath('applications.0.id', $site->id)
        ->assertJsonPath('applications.0.php_binary', '/usr/bin/php8.3');
});
