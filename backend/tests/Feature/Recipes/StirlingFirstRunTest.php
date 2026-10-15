<?php

use App\Services\Recipes\RecipeCredentials;
use App\Services\Recipes\RecipeRegistry;

require_once __DIR__.'/../../Support/Recipes/RecipeApiTestSupport.php';

beforeEach(fn () => rc08RecipeSetup());
afterEach(fn () => rc08RecipeCleanup());

it('describes the verified Stirling PDF default login without minting a secret', function () {
    $recipe = app(RecipeRegistry::class)->find('stirlingpdf');
    $site = rc03RecipeSite('stirlingpdf', ['docker_secrets' => []]);

    expect($recipe->firstRun)->toBe([
        'kind' => 'default_login',
        'credentials' => [
            ['label' => 'username', 'value' => 'admin'],
            ['label' => 'password', 'value' => 'stirling'],
        ],
    ])->and(app(RecipeCredentials::class)->for($site))->toBe([
        ['label' => 'username', 'value' => 'admin', 'secret' => false],
        ['label' => 'password', 'value' => 'stirling', 'secret' => false],
    ])->and($recipe->notes)->not->toContain('There are no accounts by default. Use panel password protection when exposing the site publicly.');

    $this->actingAs(rc08RecipeViewer('app_container', manage: true))
        ->getJson('/api/applications/'.$site->id.'/container/secrets')
        ->assertOk()->assertJsonPath('secrets', [])
        ->assertJsonPath('credentials', [
            ['label' => 'username', 'value' => 'admin', 'secret' => false],
            ['label' => 'password', 'value' => 'stirling', 'secret' => false],
        ]);

    expect($site->fresh()->docker_secrets)->toBe([]);
});
