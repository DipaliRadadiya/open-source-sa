<?php

use App\Models\User;
use App\Services\Recipes\Recipe;
use App\Services\Recipes\RecipeRegistry;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/RecipeTestSupport.php';

function rc08RecipeSetup(string $stack = 'docker'): void
{
    test()->rc08FixturePaths = [];
    rc03RecipeSetup($stack);
    config(['recipes.path' => resource_path('recipes')]);
    app(RecipeRegistry::class)->flush();
    test()->seed(PermissionSeeder::class);
    Queue::fake();
}

function rc08RecipeViewer(string $permission = 'application', bool $manage = false): User
{
    $user = User::factory()->create();
    grantPermission($user, $permission, view: true, manage: $manage);

    return $user;
}

/** A disposable recipe, never a modification to a shipped recipe or golden. */
function rc08RecipeFixture(array $overrides = []): Recipe
{
    $root = storage_path('framework/testing/rc-08-'.Str::uuid());
    test()->rc08FixturePaths = [...test()->rc08FixturePaths, $root];
    File::makeDirectory($root.'/demo_single', 0755, true);
    $source = base_path('tests/Fixtures/recipes/valid/demo_single');
    $data = json_decode(File::get($source.'/recipe.json'), true, flags: JSON_THROW_ON_ERROR);
    File::put($root.'/demo_single/recipe.json', json_encode(array_replace($data, $overrides), JSON_THROW_ON_ERROR));
    File::copy($source.'/compose.yml.tpl', $root.'/demo_single/compose.yml.tpl');
    config(['recipes.path' => $root]);
    app(RecipeRegistry::class)->flush();

    return app(RecipeRegistry::class)->find('demo_single');
}

function rc08RecipeCleanup(): void
{
    foreach (test()->rc08FixturePaths ?? [] as $root) {
        File::deleteDirectory($root);
    }
}
