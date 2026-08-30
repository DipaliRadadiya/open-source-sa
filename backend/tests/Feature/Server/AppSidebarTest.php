<?php

use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // The Administrator role is synced from the catalog, so the catalog has
    // to exist before the admin is created or the role holds nothing.
    $this->seed(PermissionSeeder::class);

    // Admin user — has every app-level permission via the Administrator role.
    $this->admin = User::factory()->admin()->create();
    Sanctum::actingAs($this->admin);

    $this->systemUser = SystemUser::factory()->create();
    $this->application = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'site_type' => 'wordpress',
    ]);
});

it('refuses a user who cannot see applications at all', function () {
    // The route is gated on the server-level `application` permission, so a
    // panel login with no grants never reaches the sidebar. 403 rather than an
    // empty list: "you may not ask" and "there is nothing here" are different
    // answers, and this endpoint is the wrong place to blur them.
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/applications/{$this->application->id}/sidebar")
        ->assertForbidden();
});

it('offers a WordPress site the screens it actually has, and no others', function () {
    // The sidebar is filtered by the site type's own feature list, so this
    // asserts the gating rather than a union no single type can satisfy. A
    // WordPress install keeps its configuration in wp-config.php, has no
    // repository and runs no worker of its own — offering those screens would
    // be offering something that cannot work.
    $names = collect($this->getJson("/api/applications/{$this->application->id}/sidebar")
        ->assertOk()->json('items'))->pluck('name');

    expect($names)->toContain('app_dashboard', 'app_domain', 'app_file', 'app_log',
        'app_backup', 'app_php', 'app_staging')
        ->and($names)->not->toContain('app_deployment')
        ->and($names)->not->toContain('app_environment')
        ->and($names)->not->toContain('app_worker');
});

it('offers a git site deployment, environment and workers, but not staging', function () {
    // The other half of the same rule. Staging is a WordPress recipe — URL
    // rewriting inside serialised data, wp-cron disabled — and exists for
    // nothing else yet, so a git site is not offered it.
    $git = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'site_type' => 'git',
    ]);

    $names = collect($this->getJson("/api/applications/{$git->id}/sidebar")
        ->assertOk()->json('items'))->pluck('name');

    expect($names)->toContain('app_deployment', 'app_environment', 'app_worker')
        ->and($names)->not->toContain('app_staging');
});

it('returns correct relative URLs', function () {
    $response = $this->getJson("/api/applications/{$this->application->id}/sidebar");

    $items = $response->json('items');
    $byName = collect($items)->keyBy('name');

    // The dashboard's url is empty on purpose: it is the application's own
    // root, so the frontend appends nothing. Asserted rather than assumed —
    // a stray '/dashboard' here would send every sidebar one level too deep.
    expect($byName['app_dashboard']['url'])->toBe('')
        ->and($byName['app_domain']['url'])->toBe('/domains')
        ->and($byName['app_file']['url'])->toBe('/files')
        ->and($byName['app_log']['url'])->toBe('/logs');
});

it('returns correct sub_level and sub_level_title', function () {
    $response = $this->getJson("/api/applications/{$this->application->id}/sidebar");

    $items = $response->json('items');
    $byName = collect($items)->keyBy('name');

    // The sidebar is scoped to one application, so every item has
    // the same sub_level_group — Application.
    expect($byName['app_dashboard']['sub_level'])->toBe('application')
        ->and($byName['app_dashboard']['sub_level_title'])->toBeString();
});

it('filters by application type — node app does not show php settings', function () {
    $nodeApp = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'site_type' => 'n8n',
    ]);

    $response = $this->getJson("/api/applications/{$nodeApp->id}/sidebar");

    $items = $response->json('items');
    $names = collect($items)->pluck('name')->values();

    expect($names)->not->toContain('app_php');
});

it('filters by application type — static site does not show workers or php', function () {
    $staticApp = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'site_type' => 'static',
    ]);

    $response = $this->getJson("/api/applications/{$staticApp->id}/sidebar");

    $items = $response->json('items');
    $names = collect($items)->pluck('name')->values();

    expect($names)->not->toContain('app_php', 'app_worker');
});
