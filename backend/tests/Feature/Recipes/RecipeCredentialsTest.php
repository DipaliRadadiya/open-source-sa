<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Recipes\RecipeCredentials;

require_once __DIR__.'/../../Support/Recipes/RecipeApiTestSupport.php';

beforeEach(fn () => rc08RecipeSetup());
afterEach(fn () => rc08RecipeCleanup());

it('curates Grafana login credentials while preserving the legacy secrets map and key-only audit', function () {
    $secrets = ['GF_SECURITY_ADMIN_PASSWORD' => 'stored-admin-value', 'EXTRA_DB_PASSWORD' => 'not-a-login'];
    $site = rc03RecipeSite('grafana', ['docker_secrets' => $secrets]);
    $before = $site->getRawOriginal('docker_secrets');
    $this->actingAs(rc08RecipeViewer('app_container', manage: true))->getJson('/api/applications/'.$site->id.'/container/secrets')
        ->assertOk()->assertExactJson([
            'secrets' => $secrets,
            'credentials' => [['label' => 'username', 'value' => 'admin', 'secret' => false], ['label' => 'password', 'value' => 'stored-admin-value', 'secret' => true]],
        ]);
    $log = ActivityLog::where('action', 'container_secrets_viewed')->sole();
    expect($log->properties)->toBe(['name' => $site->name, 'keys' => array_keys($secrets)])->and($log->user_id)->toBe(auth()->id());
    expect(json_encode($log->getAttributes()))->not->toContain('stored-admin-value', 'not-a-login');
    expect($site->fresh()->getRawOriginal('docker_secrets'))->toBe($before);
});

it('curates Chatwoot email and stored panel-only password without the database credentials', function () {
    $site = rc03RecipeSite('chatwoot', [
        'settings' => ['admin_email' => 'chat-owner@example.test'],
        'docker_secrets' => ['ADMIN_PASSWORD' => 'Complex!Stored42', 'POSTGRES_PASSWORD' => 'database-value'],
    ]);
    $this->actingAs(rc08RecipeViewer('app_container', manage: true))->getJson('/api/applications/'.$site->id.'/container/secrets')->assertOk()
        ->assertJsonPath('secrets', ['ADMIN_PASSWORD' => 'Complex!Stored42', 'POSTGRES_PASSWORD' => 'database-value'])
        ->assertJsonPath('credentials', [['label' => 'email', 'value' => 'chat-owner@example.test', 'secret' => false], ['label' => 'password', 'value' => 'Complex!Stored42', 'secret' => true]]);
});

it('returns null credentials for setup wizards and direct Docker sites', function (string $type) {
    $site = rc03RecipeSite($type, ['docker_secrets' => ['EXTRA_PASSWORD' => 'existing-value']]);
    $this->actingAs(rc08RecipeViewer('app_container', manage: true))->getJson('/api/applications/'.$site->id.'/container/secrets')->assertOk()
        ->assertJsonPath('secrets.EXTRA_PASSWORD', 'existing-value')->assertJsonPath('credentials', null);
})->with(['ghost', 'docker']);

it('returns null for native and unknown nonrecipe sites', function (string $type) {
    expect(app(RecipeCredentials::class)->for(rc03RecipeSite($type)))->toBeNull();
})->with(['php', 'missing_recipe']);

it('reads input credentials through installSettings and constructs URLs with the sites actual scheme', function () {
    rc08RecipeFixture([
        'inputs' => [['name' => 'admin_email', 'required' => false]],
        'first_run' => ['kind' => 'credentials', 'credentials' => [
            ['label' => 'email', 'input' => 'admin_email'], ['label' => 'url', 'path' => '/admin'], ['label' => 'username', 'value' => '0'],
        ]],
    ]);
    $site = rc03RecipeSite('demo_single', [
        'settings' => ['admin_email' => 'settings@example.test'], 'install_secrets' => ['admin_email' => 'install@example.test'],
    ]);
    expect(app(RecipeCredentials::class)->for($site))->toBe([
        ['label' => 'email', 'value' => 'install@example.test', 'secret' => false],
        ['label' => 'url', 'value' => $site->url('/admin'), 'secret' => false],
        ['label' => 'username', 'value' => '0', 'secret' => false],
    ]);
});

it('skips missing and blank values while retaining a JSON list', function () {
    rc08RecipeFixture([
        'inputs' => [['name' => 'admin_email', 'required' => false]],
        'first_run' => ['kind' => 'credentials', 'credentials' => [
            ['label' => 'password', 'secret' => 'PASSWORD'], ['label' => 'email', 'input' => 'admin_email'],
            ['label' => 'username', 'value' => ''], ['label' => 'url', 'path' => '/admin'],
        ]],
    ]);
    $site = rc03RecipeSite('demo_single', ['docker_secrets' => [], 'settings' => ['admin_email' => '  ']]);
    $this->actingAs(rc08RecipeViewer('app_container', manage: true))->getJson('/api/applications/'.$site->id.'/container/secrets')->assertOk()
        ->assertJsonPath('credentials', [['label' => 'url', 'value' => $site->url('/admin'), 'secret' => false]]);
});

it('returns an empty list rather than legacy fallback when all declared values are absent', function () {
    rc08RecipeFixture();
    $site = rc03RecipeSite('demo_single', ['docker_secrets' => []]);
    $this->actingAs(rc08RecipeViewer('app_container', manage: true))->getJson('/api/applications/'.$site->id.'/container/secrets')->assertOk()
        ->assertExactJson(['secrets' => [], 'credentials' => []]);
});

it('denies a view-only caller and does not record a credential read', function () {
    $site = rc03RecipeSite('grafana');
    $this->actingAs(rc08RecipeViewer('app_container'))->getJson('/api/applications/'.$site->id.'/container/secrets')->assertForbidden();
    expect(ActivityLog::where('action', 'container_secrets_viewed')->count())->toBe(0);
});

it('requires authentication to read recipe credentials', function () {
    $site = rc03RecipeSite('grafana');
    $this->getJson('/api/applications/'.$site->id.'/container/secrets')->assertUnauthorized();
});

it('keeps the existing six-per-minute credential throttle', function () {
    $site = rc03RecipeSite('grafana');
    $this->actingAs(User::factory()->admin()->create());
    $url = '/api/applications/'.$site->id.'/container/secrets';
    for ($i = 0; $i < 6; $i++) {
        $this->getJson($url)->assertOk();
    }
    $this->getJson($url)->assertTooManyRequests();
    expect(ActivityLog::where('action', 'container_secrets_viewed')->count())->toBe(6);
});

it('uses HTTPS for a path credential only once the sites certificate can serve its hostname', function () {
    rc08RecipeFixture(['first_run' => ['kind' => 'credentials', 'credentials' => [['label' => 'url', 'path' => '/admin']]]]);
    $site = rc03RecipeSite('demo_single');
    $site->certificate()->create([
        'type' => 'letsencrypt', 'status' => 'active', 'domains' => [$site->domain],
        'certificate_path' => '/fixture/cert.pem', 'private_key_path' => '/fixture/key.pem',
    ]);
    $this->actingAs(rc08RecipeViewer('app_container', manage: true))->getJson('/api/applications/'.$site->id.'/container/secrets')->assertOk()
        ->assertJsonPath('credentials', [['label' => 'url', 'value' => 'https://'.$site->domain.'/admin', 'secret' => false]]);
});
