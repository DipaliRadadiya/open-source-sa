<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
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
        // Distinct per call: application names are unique.
        'name' => 'Site '.$siteType,
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
    // A WordPress install has no repository, branch or commit history: absent, not disabled.
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

it('does not offer PHP settings on a git site that serves no PHP', function () {
    // The git site type says `php` by default, but the row records the `static` or `ssr` the
    // user picked, and the row wins. Only git can disagree with its own type.
    $static = makeFeatureApp('git', 'static', ['name' => 'Git static', 'domain' => 'git-static.example.com']);
    $node = makeFeatureApp('git', 'node', ['name' => 'Git ssr', 'domain' => 'git-ssr.example.com']);

    expect(sidebarFor($static))->not->toContain('app_php')
        ->and(sidebarFor($node))->not->toContain('app_php')
        // Everything else a git site has is unaffected.
        ->and(sidebarFor($static))->toContain('app_deployment');
});

it('still offers PHP settings on a git site that does serve PHP', function () {
    expect(sidebarFor(makeFeatureApp('git')))->toContain('app_php');
});

it('offers workers on a Node site but not on a one-click PHP one', function () {
    expect(sidebarFor(makeFeatureApp('uptimekuma', 'node')))->toContain('app_worker')
        ->and(sidebarFor(makeFeatureApp('joomla')))->not->toContain('app_worker');
});

it('offers an environment file only where the panel owns one', function () {
    // WordPress keeps its configuration in wp-config.php, not an env file the panel manages.
    expect(sidebarFor(makeFeatureApp('wordpress')))->not->toContain('app_environment')
        ->and(sidebarFor(makeFeatureApp('git')))->toContain('app_environment')
        // Craft and Statamic are the marketplace exceptions: both read .env.
        ->and(sidebarFor(makeFeatureApp('craftcms')))->toContain('app_environment')
        ->and(sidebarFor(makeFeatureApp('statamic')))->toContain('app_environment')
        // And Akaunting (Laravel) and Mautic (Symfony).
        ->and(sidebarFor(makeFeatureApp('akaunting')))->toContain('app_environment')
        ->and(sidebarFor(makeFeatureApp('mautic')))->toContain('app_environment');
});

it('offers staging only where a staging recipe exists', function () {
    // Pushing a staging site back needs URL rewriting inside serialised data; only WordPress has it.
    expect(sidebarFor(makeFeatureApp('wordpress')))->toContain('app_staging')
        ->and(sidebarFor(makeFeatureApp('git')))->not->toContain('app_staging');
});

it('does not offer backups or cloning for a database client', function (string $type) {
    // It holds no content of its own; it reads the databases already on the server.
    $sidebar = sidebarFor(makeFeatureApp($type));

    expect($sidebar)->not->toContain('app_backup')
        ->and($sidebar)->not->toContain('app_clone')
        // Password protection stays: an exposed phpMyAdmin is a login page for every database.
        ->and($sidebar)->toContain('app_security');
})->with(['phpmyadmin', 'adminer']);

it('offers cloning only where copying the files is the whole job', function () {
    // A file-only clone of a database-backed site points at the original's database, so
    // `CloneManager` refuses it. WordPress is the one database-backed type with a recipe.
    expect(sidebarFor(makeFeatureApp('wordpress')))->toContain('app_clone')
        ->and(sidebarFor(makeFeatureApp('static', 'static')))->toContain('app_clone')
        ->and(sidebarFor(makeFeatureApp('git')))->toContain('app_clone')
        ->and(sidebarFor(makeFeatureApp('uptimekuma', 'node')))->toContain('app_clone')
        ->and(sidebarFor(makeFeatureApp('moodle')))->not->toContain('app_clone')
        ->and(sidebarFor(makeFeatureApp('nodebb', 'node')))->not->toContain('app_clone');
});

it('pins which site types offer cloning', function () {
    // Pins the list: a new type with a database and no clone recipe must not quietly gain the
    // screen, and a type that grows a `cloneStrategy()` must add `app_clone` to its `features()`.
    $offering = collect(app(SiteTypeManager::class)->all())
        ->filter(fn ($type) => in_array('app_clone', $type->features(), true))
        ->map(fn ($type) => $type->name())
        ->sort()
        ->values()
        ->all();

    expect($offering)->toBe([
        'git', 'n8n', 'nodered', 'php', 'statamic', 'static', 'uptimekuma', 'wordpress',
    ]);
});

it('still returns every application permission for the role form', function () {
    // Assigning a role is not about one site, so the unfiltered list must stay available.
    $all = collect(
        $this->actingAs($this->admin)
            ->getJson('/api/permissions?level=application')
            ->assertOk()
            ->json('permissions')
    )->pluck('name');

    expect($all)->toHaveCount(18)
        ->and($all)->toContain('app_deployment')
        ->and($all)->toContain('app_staging')
        // Container sites only, filtered per site type, but grantable here.
        ->and($all)->toContain('app_compose')
        // `app_magic_login` is not a screen (null url, so the sidebar skips it) but is still grantable.
        ->and($all)->toContain('app_magic_login');
});

it('leaves the server sidebar untouched when an application is named', function () {
    $application = makeFeatureApp('wordpress');

    $server = collect(
        $this->actingAs($this->admin)
            ->getJson("/api/permissions?level=server&application_id={$application->id}")
            ->assertOk()
            ->json('permissions')
    )->pluck('name');

    // The filter only removes application-level rows.
    expect($server)->toContain('application')
        ->and($server)->toContain('database');
});

it('closes the door as well as hiding the link', function () {
    $application = makeFeatureApp('wordpress');

    // A hidden sidebar item is not authorisation. 404 rather than 403: for this site the
    // screen does not exist.
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

it('offers backups to container sites, and still no cloning', function (string $siteType) {
    // `ArchiveVolumes` and `ArchiveFiles` capture the volumes and compose file, so backups are
    // offered. Cloning must not be: it copies served files, so a clone would point at the
    // original's volumes.
    //
    // Asserted per type: the generic Docker type and the recipe-backed type strip features
    // independently.
    $sidebar = sidebarFor(makeFeatureApp($siteType, 'docker'));

    expect($sidebar)->toContain('app_backup')
        ->and($sidebar)->not->toContain('app_clone')
        // Still a container site otherwise.
        ->and($sidebar)->toContain('app_container')
        ->and($sidebar)->toContain('app_compose');
})->with([
    'bring-your-own image' => 'docker',
    'a one-click app' => 'ghost',
]);
