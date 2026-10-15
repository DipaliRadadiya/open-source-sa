<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Services\Applications\SiteTypeManager;
use Illuminate\Http\JsonResponse;

class RecipeController extends Controller
{
    public function index(SiteTypeManager $manager): JsonResponse
    {
        return response()->json(['recipes' => array_values(array_filter(
            $manager->catalog(),
            fn (array $type): bool => $type['recipe'] !== null,
        ))]);
    }

    public function show(string $recipe, SiteTypeManager $manager): JsonResponse
    {
        $entry = collect($manager->catalog())->first(fn (array $type): bool => $type['name'] === $recipe && $type['recipe'] !== null);
        abort_if($entry === null, 404);

        return response()->json(['recipe' => $entry]);
    }
}
