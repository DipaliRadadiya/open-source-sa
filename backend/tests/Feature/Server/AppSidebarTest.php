<?php

use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Admin user — has every app-level permission via the Administrator role.
    $this->admin = SystemUser::factory()->admin()->make();
    Sanctum::actingAs($this->admin);

    $this->systemUser = SystemUser::factory()->create();
    $this->application = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'site_type' => 'wordpress',
    ]);
});

it('returns only app-level permissions the user has', function () {
    // A user with no app-level roles gets an empty sidebar.
    $viewer = SystemUser::factory()->create();
    Sanctum::actingAs($viewer);

    $response = $this->getJson("/api/applications/{$this->application->id}/sidebar");

    $response->assertOk()
        ->assertJson(['items' => []]);
});

it('returns items for a user with app permissions', function () {
    $response = $this->getJson("/api/applications/{$this->application->id}/sidebar");

    $response->assertOk();

    $items = $response->json('items');
    $names = collect($items)->pluck('name')->values();

    // A WordPress git app should include deployment, environment, workers,
    // files, logs, backups, php settings, staging, and its own dashboard.
    expect($names)->toContain('app_dashboard', 'app_domain', 'app_deployment',
        'app_environment', 'app_worker', 'app_file', 'app_log', 'app_backup',
        'app_php', 'app_staging');
});

it('returns correct relative URLs', function () {
    $response = $this->getJson("/api/applications/{$this->application->id}/sidebar");

    $items = $response->json('items');
    $byName = collect($items)->keyBy('name');

    expect($byName['app_dashboard']['url'])->toBe('/dashboard')
        ->and($byName['app_domain']['url'])->toBe('/domains')
        ->and($byName['app_deployment']['url'])->toBe('/deployment')
        ->and($byName['app_worker']['url'])->toBe('/workers')
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
