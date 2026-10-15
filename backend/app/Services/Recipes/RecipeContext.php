<?php

namespace App\Services\Recipes;

use App\Models\Application;
use App\Services\Recipes\Exceptions\RecipeRenderException;
use App\Services\Server\Applications\ContainerSupervisor;

final class RecipeContext
{
    /** @return array<string, string|int|null> */
    public function build(Recipe $recipe, Application $application, string $url, array $secrets, string $documentRoot): array
    {
        $project = app(ContainerSupervisor::class)->project($application);
        $values = [
            'project' => $project,
            'app_port' => (int) $application->app_port,
            'container_port' => $recipe->containerPort,
            'memory_limit' => (string) ($application->memory_limit ?: $recipe->memoryFloor ?: config('server.docker.default_memory_limit', '512m')),
            'db_memory_limit' => (string) config('server.docker.default_db_memory_limit', '512m'),
            'cpu_limit' => $application->cpu_limit ?: null,
            'url' => $url,
            'domain' => (string) $application->domain,
            'site_root' => rtrim($documentRoot, '/'),
        ];

        foreach ($recipe->images as $role => $image) {
            $values['image.'.$role] = (string) (config("recipes.image_overrides.{$recipe->slug}.{$role}") ?: $image['ref']);
        }

        foreach ($recipe->volumes as $role => $path) {
            $values['volume.'.$role] = $project.'_'.$role;
        }

        foreach ($recipe->secrets as $secret) {
            $key = $secret['key'];
            if (! isset($secrets[$key]) || ! is_string($secrets[$key])) {
                throw new RecipeRenderException('secret.'.$key);
            }
            $values['secret.'.$key] = $secrets[$key];
        }

        $settings = $application->installSettings();
        foreach ($recipe->inputs as $input) {
            $name = $input['name'];
            $value = $settings[$name] ?? null;
            if ($value !== null && ! is_string($value)) {
                throw new RecipeRenderException('input.'.$name);
            }
            $values['input.'.$name] = $value;
        }

        return $values;
    }
}
