<?php

namespace App\Services\Recipes;

final class RecipePresenter
{
    public function summary(Recipe $recipe): array
    {
        return [
            'slug' => $recipe->slug,
            'version' => $recipe->version,
            'description' => $recipe->description(app()->getLocale()),
            'services' => array_values($recipe->services),
            'volumes' => array_map(
                fn (string $role, string $path): array => ['role' => $role, 'path' => $path],
                array_keys($recipe->volumes),
                array_values($recipe->volumes),
            ),
            'images' => array_map(
                fn (string $role, array $image): array => ['role' => $role, 'ref' => $image['ref'], 'tested' => array_values($image['tested'])],
                array_keys($recipe->images),
                array_values($recipe->images),
            ),
            'memory_floor' => $recipe->memoryFloor,
            'first_run' => [
                'kind' => $recipe->firstRun['kind'],
                'credential_labels' => array_column($recipe->firstRun['credentials'] ?? [], 'label'),
            ],
            'inputs' => array_column($recipe->inputs, 'name'),
            'docs_url' => $recipe->docsUrl,
            'icon_data_uri' => $recipe->iconDataUri,
        ];
    }
}
