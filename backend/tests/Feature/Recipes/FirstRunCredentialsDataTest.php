<?php

use App\Services\Recipes\RecipeRegistry;

it('declares only the generated Grafana admin login as first-run credentials', function () {
    config(['recipes.path' => resource_path('recipes')]);
    app(RecipeRegistry::class)->flush();

    $recipe = app(RecipeRegistry::class)->find('grafana');

    expect($recipe)->not->toBeNull()
        ->and($recipe->firstRun)->toBe([
            'kind' => 'credentials',
            'credentials' => [
                ['label' => 'username', 'value' => 'admin'],
                ['label' => 'password', 'secret' => 'GF_SECURITY_ADMIN_PASSWORD'],
            ],
        ]);
});
