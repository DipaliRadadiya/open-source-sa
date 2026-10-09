<?php

use App\Services\Recipes\RecipeRegistry;

it('validates fixture recipes and accepts the empty shipped registry', function () {
    config(['recipes.path' => base_path('tests/Fixtures/recipes/valid')]);
    app(RecipeRegistry::class)->flush();
    $this->artisan('recipes:validate')->expectsOutput('OK demo_multi v1')->expectsOutput('OK demo_single v1')->expectsOutput('OK demo_claim v1')->assertExitCode(0);
    config(['recipes.path' => resource_path('recipes')]);
    app(RecipeRegistry::class)->flush();
    $this->artisan('recipes:validate')->expectsOutput('No recipes found in '.resource_path('recipes'))->assertExitCode(0);
});
it('exits nonzero for invalid recipes', function () {
    config(['recipes.path' => base_path('tests/Fixtures/recipes/invalid')]);
    app(RecipeRegistry::class)->flush();
    $this->artisan('recipes:validate')->assertExitCode(1);
});
