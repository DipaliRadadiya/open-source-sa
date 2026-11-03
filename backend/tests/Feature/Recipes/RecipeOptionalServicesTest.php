<?php

use App\Services\Recipes\Exceptions\InvalidRecipeException;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Recipes\RecipeSchema;

/**
 * A recipe service marked `"optional": true` is a helper: its trouble is a
 * named warning on the app, not a failed app (health badge, 2026-11-03). The
 * flag can hide an outage, so it is opt-in, never on the app's own service,
 * and the shipped list is pinned here.
 */
it('resolves every shipped recipe to exactly one app service, never optional', function () {
    config(['recipes.path' => resource_path('recipes')]);
    app(RecipeRegistry::class)->flush();

    foreach (app(RecipeRegistry::class)->all() as $slug => $recipe) {
        $apps = array_filter($recipe->services, fn (array $service): bool => $service['role'] === 'app');

        expect($apps)->toHaveCount(1, $slug)
            ->and($recipe->optionalServices())->not->toContain($recipe->appService()['name']);

        foreach ($recipe->services as $service) {
            expect($service['optional'] ?? false)->toBeBool();
        }
    }
});

it('marks only the services decided to be helpers as optional', function () {
    // Adding one hides that service's failures from the badge. Decide it for
    // the recipe, live-tested, and add it here.
    config(['recipes.path' => resource_path('recipes')]);
    app(RecipeRegistry::class)->flush();

    $optional = array_filter(array_map(
        fn ($recipe) => $recipe->optionalServices(),
        app(RecipeRegistry::class)->all(),
    ));

    expect($optional)->toBe(['supabase' => ['functions']]);
});

it('accepts optional as a strict boolean on a non-app service only', function () {
    $directory = base_path('tests/Fixtures/recipes/valid/demo_multi');
    $data = json_decode(file_get_contents($directory.'/recipe.json'), true);
    $helper = array_key_first(array_filter($data['services'], fn (array $service): bool => $service['role'] !== 'app'));
    $app = array_key_first(array_filter($data['services'], fn (array $service): bool => $service['role'] === 'app'));

    $valid = $data;
    $valid['services'][$helper]['optional'] = true;
    RecipeSchema::check($valid, $directory);

    $valid['services'][$helper]['optional'] = false;
    RecipeSchema::check($valid, $directory);

    foreach (['as a string' => [$helper, 'true'], 'on the app service' => [$app, true]] as $case => [$index, $value]) {
        $invalid = $data;
        $invalid['services'][$index]['optional'] = $value;

        expect(fn () => RecipeSchema::check($invalid, $directory))
            ->toThrow(InvalidRecipeException::class, 'schema', $case);
    }
});
