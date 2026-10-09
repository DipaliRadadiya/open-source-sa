<?php

use App\Models\Application;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/Recipes/RecipeTestSupport.php';

beforeEach(function () {
    rc03RecipeSetup();
    $this->seed(PermissionSeeder::class);
    Queue::fake();
});

function rc03CreatePayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Demo Application', 'domain' => 'create.example.test', 'site_type' => 'demo_claim',
        'generate_system_user' => true, 'admin_email' => 'owner@example.test',
        'memory_limit' => '640m', 'cpu_limit' => '1.5',
    ];
}

it('exposes recipes through the authenticated catalog and creates using existing request validation', function () {
    $this->actingAs(User::factory()->admin()->create())->getJson('/api/site-types')->assertOk()
        ->assertJsonFragment(['name' => 'demo_claim', 'title' => 'Demo']);
    $response = $this->postJson('/api/applications', rc03CreatePayload())->assertCreated();
    $site = Application::findOrFail($response->json('application.id'));
    expect($site->site_type)->toBe('demo_claim')->and($site->serving_profile)->toBe('docker')
        ->and($site->installSettings()['admin_email'])->toBe('owner@example.test')->and($site->memory_limit)->toBe('640m');
});

it('validates declared recipe inputs before recording an application', function () {
    $this->actingAs(User::factory()->admin()->create())->postJson('/api/applications', rc03CreatePayload(['admin_email' => 'not-an-email']))
        ->assertUnprocessable()->assertJsonValidationErrors('admin_email');
    expect(Application::count())->toBe(0);
});

it('denies recipe creation to a user without application permissions', function () {
    $this->actingAs(User::factory()->create())->postJson('/api/applications', rc03CreatePayload())->assertForbidden();
    expect(Application::count())->toBe(0);
});

it('denies unauthenticated recipe creation', function () {
    $this->postJson('/api/applications', rc03CreatePayload())->assertUnauthorized();
    expect(Application::count())->toBe(0);
});

it('refuses recipe creation on non-Docker servers even with valid inputs', function () {
    rc03RecipeSetup('lemp');
    $this->actingAs(User::factory()->admin()->create())->postJson('/api/applications', rc03CreatePayload())
        ->assertUnprocessable()->assertJsonValidationErrors('site_type');
    expect(Application::count())->toBe(0);
});
