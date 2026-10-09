<?php

namespace App\Services\Recipes\Hooks;

use App\Models\Application;
use App\Services\Recipes\Recipe;

interface RecipeHook
{
    /** @return array{path:string,fields:array<string,string>}|null */
    public function firstRunClaim(Application $application, Recipe $recipe): ?array;
}
