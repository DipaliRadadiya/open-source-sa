<?php

use App\Services\Recipes\RecipeRegistry;

it('validates fixture recipes and accepts an explicitly empty registry', function () {
    config(['recipes.path' => base_path('tests/Fixtures/recipes/valid'), 'recipes.hook_namespaces' => ['App\\Services\\Recipes\\Hooks\\', 'Tests\\Support\\Recipes\\']]);
    app(RecipeRegistry::class)->flush();
    $this->artisan('recipes:validate')->expectsOutput('OK demo_multi v1')->expectsOutput('OK demo_single v1')->expectsOutput('OK demo_claim v1')->expectsOutput('OK demo_hook v1')->assertExitCode(0);
    $empty = sys_get_temp_dir().'/rc04-validate-'.bin2hex(random_bytes(6));
    mkdir($empty);
    try {
        // Do not assume resources/recipes stays empty after migrations begin.
        config(['recipes.path' => $empty]);
        app(RecipeRegistry::class)->flush();
        $this->artisan('recipes:validate')->expectsOutput('No recipes found in '.$empty)->assertExitCode(0);
    } finally {
        rmdir($empty);
    }
});
it('exits nonzero for invalid recipes', function () {
    config(['recipes.path' => base_path('tests/Fixtures/recipes/invalid')]);
    app(RecipeRegistry::class)->flush();
    $this->artisan('recipes:validate')->assertExitCode(1);
});
