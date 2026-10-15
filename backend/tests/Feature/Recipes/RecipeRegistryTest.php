<?php

use App\Services\Recipes\Exceptions\InvalidRecipeException;
use App\Services\Recipes\RecipeRegistry;

it('memoizes the sorted registry and supports explicit flushing', function () {
    config(['recipes.path' => base_path('tests/Fixtures/recipes/valid'), 'recipes.hook_namespaces' => ['App\\Services\\Recipes\\Hooks\\', 'Tests\\Support\\Recipes\\']]);
    $r = app(RecipeRegistry::class);
    $r->flush();
    expect(array_keys($r->all()))->toBe(['demo_multi', 'demo_single', 'demo_claim', 'demo_hook'])->and($r->has('demo_single'))->toBeTrue()->and($r->find('missing'))->toBeNull()->and(app(RecipeRegistry::class))->toBe($r);
    $empty = sys_get_temp_dir().'/rc04-registry-'.bin2hex(random_bytes(6));
    mkdir($empty);
    try {
        // Shipped recipes now exist; use an explicitly empty directory instead.
        config(['recipes.path' => $empty]);
        expect($r->has('demo_single'))->toBeTrue();
        $r->flush();
        expect($r->all())->toBe([]);
    } finally {
        rmdir($empty);
    }
});
it('accepts an empty directory and rejects a recipe-less folder', function () {
    $dir = sys_get_temp_dir().'/rc02-'.bin2hex(random_bytes(6));
    mkdir($dir);
    try {
        config(['recipes.path' => $dir]);
        $r = app(RecipeRegistry::class);
        $r->flush();
        expect($r->all())->toBe([]);
        mkdir($dir.'/missing');
        $r->flush();
        expect(fn () => $r->all())->toThrow(InvalidRecipeException::class, 'recipe_json_missing');
    } finally {
        rmdir($dir.'/missing');
        rmdir($dir);
    }
});
