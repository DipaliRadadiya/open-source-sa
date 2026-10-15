<?php

namespace App\Services\Recipes;

use App\Models\Application;

final class ApplicationOrigin
{
    public function __construct(private RecipeRegistry $recipes) {}

    public function describe(Application $application): array
    {
        $recipe = $this->recipes->find((string) $application->site_type);

        if ($recipe !== null) {
            return [
                'kind' => 'recipe',
                'recipe' => [
                    'slug' => $recipe->slug,
                    'title' => $recipe->title,
                    'version' => $recipe->version,
                    'deprecated' => $recipe->deprecated,
                ],
                'image' => null,
            ];
        }

        $kind = $application->site_type === 'docker'
            ? (trim((string) $application->compose) === '' ? 'image' : 'compose')
            : 'other';

        return ['kind' => $kind, 'recipe' => null, 'image' => $kind === 'image' ? $application->image : null];
    }
}
