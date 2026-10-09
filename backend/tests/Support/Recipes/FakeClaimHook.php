<?php

namespace Tests\Support\Recipes;

use App\Models\Application;
use App\Services\Recipes\Hooks\RecipeHook;
use App\Services\Recipes\Recipe;

final class FakeClaimHook implements RecipeHook
{
    public function firstRunClaim(Application $application, Recipe $recipe): ?array
    {
        return ['path' => '/hook-setup', 'fields' => ['owner' => 'fixed-owner']];
    }
}
