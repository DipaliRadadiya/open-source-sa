<?php

namespace App\Services\Recipes;

use App\Models\Application;

final class RecipeCredentials
{
    public function __construct(private RecipeRegistry $recipes) {}

    public function for(Application $application): ?array
    {
        $recipe = $this->recipes->find((string) $application->site_type);
        $credentials = $recipe?->firstRun['credentials'] ?? null;

        if ($credentials === null) {
            return null;
        }

        $items = [];
        foreach ($credentials as $credential) {
            $value = match (true) {
                isset($credential['value']) => $credential['value'],
                isset($credential['secret']) => $application->docker_secrets[$credential['secret']] ?? null,
                isset($credential['input']) => $application->installSettings()[$credential['input']] ?? null,
                default => $application->url($credential['path']),
            };

            if (blank($value)) {
                continue;
            }

            $items[] = ['label' => $credential['label'], 'value' => $value, 'secret' => isset($credential['secret'])];
        }

        return $items;
    }
}
