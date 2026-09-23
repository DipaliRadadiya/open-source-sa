<?php

use App\Exceptions\Server\Application\SystemUserMissingException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * A site whose system user is gone from the panel's database.
 *
 * The schema forbids it — `system_user_id` is a required foreign key that
 * cascades — but a row can still arrive that way (imported data, a database
 * without enforced foreign keys). Reported from a real panel:
 *
 *   POST api/applications/{application}/directory-size
 *   Attempt to read property "username" on null
 *
 * About a hundred call sites assume the user is there, so the guard is one
 * middleware on every application route, plus Application::rootPath()
 * refusing to build a path with no home under it.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    config([
        'server.web_server_drivers.nginx.sites_dir' => '/etc/nginx/sites-enabled',
        'server.web_server_drivers.nginx.sites_available_dir' => '/etc/nginx/sites-available',
    ]);

    $user = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);

    // `etc` on purpose: the slug a site named "etc" gets, and the directory
    // `/{slug}` pointed at before rootPath() refused.
    $this->application = Application::forceCreate([
        'system_user_id' => $user->id,
        'name' => 'etc',
        'slug' => 'etc',
        'domain' => 'etc.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
    ]);

    // The broken state itself: the site pointing at a user that is not there.
    // Deferred rather than disabled — SQLite ignores `foreign_keys = OFF`
    // inside a transaction, and the test's transaction never commits, so a
    // deferred check is never made.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    DB::table('applications')->where('id', $this->application->id)->update(['system_user_id' => $user->id + 1000]);
});

it('answers the reported route with a sentence, not a 500', function () {
    Process::fake();

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/directory-size")
        ->assertStatus(409)
        ->assertJsonPath('message', __('errors/application.system_user_missing', ['name' => 'etc']));

    Process::assertNothingRan();
});

it('refuses every other screen of the site the same way', function (string $method, string $suffix) {
    Process::fake();

    $this->actingAs($this->admin)
        ->json($method, "/api/applications/{$this->application->id}{$suffix}")
        ->assertStatus(409);
})->with([
    'file list' => ['GET', '/files'],
    'php settings' => ['GET', '/php'],
    'environment' => ['GET', '/environment'],
    'logs' => ['GET', '/logs'],
]);

it('still opens the site, with no path to show', function () {
    Process::fake();

    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}")
        ->assertOk()
        ->assertJsonPath('application.document_root', null)
        ->assertJsonPath('application.path', null);
});

it('still lists the site', function () {
    Process::fake();

    $this->actingAs($this->admin)->getJson('/api/applications')->assertOk();
});

it('deletes it without touching a path it cannot know', function () {
    Process::fake();

    $this->actingAs($this->admin)
        ->deleteJson("/api/applications/{$this->application->id}?remove_files=1")
        ->assertOk();

    expect(Application::find($this->application->id))->toBeNull();

    // `rm -rf /etc`, as root, is what the delete ran before.
    Process::assertNotRan(fn ($process) => in_array('-rf', (array) $process->command, true));
});

it('will not build a path with no home under it', function () {
    expect(fn () => $this->application->fresh()->rootPath())
        ->toThrow(SystemUserMissingException::class);
});
