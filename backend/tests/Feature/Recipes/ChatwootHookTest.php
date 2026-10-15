<?php

use App\Models\Application;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\Types\RecipeSiteType;
use App\Services\Recipes\Hooks\ChatwootHook;
use App\Services\Recipes\RecipeRegistry;

beforeEach(function () {
    config(['recipes.path' => resource_path('recipes')]);
    app(RecipeRegistry::class)->flush();
});

it('does not claim Chatwoot without an admin email or password', function (array $settings, array $secrets) {
    $application = new Application;
    $application->forceFill(['name' => 'Support', 'settings' => $settings, 'docker_secrets' => $secrets]);
    $recipe = app(RecipeRegistry::class)->find('chatwoot');

    expect(app(ChatwootHook::class)->firstRunClaim($application, $recipe))->toBeNull();
})->with([
    'missing email' => [[], ['ADMIN_PASSWORD' => 'Aa1!'.str_repeat('a', 28)]],
    'empty email' => [['admin_email' => ''], ['ADMIN_PASSWORD' => 'Aa1!'.str_repeat('a', 28)]],
    'missing password' => [['admin_email' => 'owner@example.test'], []],
    'empty password' => [['admin_email' => 'owner@example.test'], ['ADMIN_PASSWORD' => '']],
]);

it('claims Chatwoot through its recipe hook with the exact legacy field order and no telemetry', function () {
    $application = new Application;
    $application->forceFill([
        'name' => 'Support & Team',
        'settings' => ['admin_email' => 'owner+support@example.test'],
        'docker_secrets' => ['ADMIN_PASSWORD' => 'Aa1!'.str_repeat('a', 28)],
    ]);
    $recipe = app(RecipeRegistry::class)->find('chatwoot');
    $expected = [
        'path' => '/installation/onboarding',
        'fields' => [
            'user[name]' => 'Admin',
            'user[company]' => 'Support & Team',
            'user[email]' => 'owner+support@example.test',
            'user[password]' => $application->docker_secrets['ADMIN_PASSWORD'],
        ],
    ];
    $type = app(SiteTypeManager::class)->find('chatwoot');

    expect($recipe->hook)->toBe(ChatwootHook::class)
        ->and($recipe->afterInstall)->toBeNull()
        ->and($type)->toBeInstanceOf(RecipeSiteType::class)
        ->and(app(ChatwootHook::class)->firstRunClaim($application, $recipe))->toBe($expected)
        ->and($type->firstRunClaim($application))->toBe($expected)
        ->and($expected['fields'])->not->toHaveKey('subscribe_to_updates');
});

it('declares only the Chatwoot owner login as first-run credentials', function () {
    $recipe = app(RecipeRegistry::class)->find('chatwoot');

    expect($recipe->firstRun)->toBe([
        'kind' => 'claimed',
        'credentials' => [
            ['label' => 'email', 'input' => 'admin_email'],
            ['label' => 'password', 'secret' => 'ADMIN_PASSWORD'],
        ],
    ])->and($recipe->inputs)->toBe([['name' => 'admin_email', 'required' => true]])
        ->and($recipe->panelOnlySecretKeys())->toBe(['ADMIN_PASSWORD']);
});

it('declares BookStack documented default login without exposing its encryption or database secrets', function () {
    $recipe = app(RecipeRegistry::class)->find('bookstack');

    expect($recipe->firstRun)->toBe([
        'kind' => 'default_login',
        'credentials' => [
            ['label' => 'email', 'value' => 'admin@admin.com'],
            ['label' => 'password', 'value' => 'password'],
        ],
    ]);
});
