<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->systemUser = SystemUser::create([
        'username' => 'featuser', 'home_path' => '/home/featuser', 'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function makeFeatureApp(string $siteType, string $profile = 'php', array $extra = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->systemUser->id,
        'name' => 'Site',
        'domain' => $siteType.'.example.com',
        'site_type' => $siteType,
        'serving_profile' => $profile,
        'php_version' => $profile === 'php' ? '8.4' : null,
        'web_root' => '/',
        'status' => 'active',
    ], $extra));
}

function sidebarFor(Application $application): array
{
    return collect(
        test()->actingAs(test()->admin)
            ->getJson("/api/permissions?level=application&application_id={$application->id}")
            ->assertOk()
            ->json('permissions')
    )->pluck('name')->all();
}

it('does not offer Deployment on a site with no repository', function () {
    // A WordPress install has no repository, no branch and no commit history.
    // The screen would be about nothing — so it is absent, not disabled.
    expect(sidebarFor(makeFeatureApp('wordpress')))->not->toContain('app_deployment');
});

it('offers Deployment on a git site', function () {
    expect(sidebarFor(makeFeatureApp('git')))->toContain('app_deployment');
});

it('does not offer PHP settings on a static site', function () {
    $sidebar = sidebarFor(makeFeatureApp('static', 'static'));

    expect($sidebar)->not->toContain('app_php')
        ->and($sidebar)->not->toContain('app_worker')
        // What every site has, whatever it is.
        ->and($sidebar)->toContain('app_domain')
        ->and($sidebar)->toContain('app_log');
});

it('offers workers on a Node site but not on a one-click PHP one', function () {
    expect(sidebarFor(makeFeatureApp('uptimekuma', 'node')))->toContain('app_worker')
        ->and(sidebarFor(makeFeatureApp('joomla')))->not->toContain('app_worker');
});

it('offers an environment file only where the panel owns one', function () {
    // WordPress keeps its configuration in wp-config.php — the application's
    // file, not an env file the panel manages.
    expect(sidebarFor(makeFeatureApp('wordpress')))->not->toContain('app_environment')
        ->and(sidebarFor(makeFeatureApp('git')))->toContain('app_environment')
        // Craft and Statamic are the marketplace exceptions: both read .env.
        ->and(sidebarFor(makeFeatureApp('craftcms')))->toContain('app_environment')
        ->and(sidebarFor(makeFeatureApp('statamic')))->toContain('app_environment');
});

it('offers staging only where a staging recipe exists', function () {
    // Pushing a WordPress staging site back needs URL rewriting inside
    // serialised data. That recipe exists for WordPress and nothing else yet.
    expect(sidebarFor(makeFeatureApp('wordpress')))->toContain('app_staging')
        ->and(sidebarFor(makeFeatureApp('git')))->not->toContain('app_staging');
});

it('does not offer backups or cloning for phpMyAdmin', function () {
    // It holds no content of its own — it reads the databases already on the
    // server. Reinstalling is the honest recovery path.
    $sidebar = sidebarFor(makeFeatureApp('phpmyadmin'));

    expect($sidebar)->not->toContain('app_backup')
        ->and($sidebar)->not->toContain('app_clone')
        // Password protection stays: an exposed phpMyAdmin is a login page for
        // every database on the box.
        ->and($sidebar)->toContain('app_security');
});

it('still returns every application permission for the role form', function () {
    // An admin assigning a role is not looking at one site, so the unfiltered
    // list has to stay available — two different questions, two answers.
    $all = collect(
        $this->actingAs($this->admin)
            ->getJson('/api/permissions?level=application')
            ->assertOk()
            ->json('permissions')
    )->pluck('name');

    expect($all)->toHaveCount(15)
        ->and($all)->toContain('app_deployment')
        ->and($all)->toContain('app_staging');
});

it('leaves the server sidebar untouched when an application is named', function () {
    $application = makeFeatureApp('wordpress');

    $server = collect(
        $this->actingAs($this->admin)
            ->getJson("/api/permissions?level=server&application_id={$application->id}")
            ->assertOk()
            ->json('permissions')
    )->pluck('name');

    // The filter only ever removes application-level rows. A server permission
    // has nothing to do with any one site.
    expect($server)->toContain('application')
        ->and($server)->toContain('database');
});

it('closes the door as well as hiding the link', function () {
    $application = makeFeatureApp('wordpress');

    // A hidden sidebar item is not authorisation — the endpoint is reachable by
    // anyone who types the URL, and would run against a site with no
    // repository. 404 rather than 403: for this site the screen does not
    // exist, which is a different statement from "you may not".
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$application->id}/deploy")
        ->assertNotFound();
});

it('keeps a supported endpoint reachable', function () {
    $application = makeFeatureApp('git');

    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$application->id}/domains")
        ->assertOk();
});

it('rejects an application id that does not exist', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/permissions?level=application&application_id=999999')
        ->assertStatus(422)
        ->assertJsonValidationErrors('application_id');
});
