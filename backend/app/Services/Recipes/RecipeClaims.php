<?php

namespace App\Services\Recipes;

use App\Models\Application;
use App\Services\Recipes\Hooks\RecipeHook;

final class RecipeClaims
{
    /** @return array{path: string, fields: array<string, string>}|null */
    public function for(Recipe $recipe, Application $application): ?array
    {
        if ($recipe->hook !== null) {
            /** @var RecipeHook $hook */
            $hook = app($recipe->hook);

            return $hook->firstRunClaim($application, $recipe);
        }

        if (($recipe->afterInstall['type'] ?? null) !== 'http_form_claim') {
            return null;
        }

        $settings = $application->installSettings();
        $secrets = (array) ($application->docker_secrets ?? []);
        $fields = [];
        $missing = false;

        foreach ($recipe->afterInstall['fields'] as $field => $template) {
            $fields[$field] = preg_replace_callback(
                '/\{\{\s*(app\.name|input\.[A-Za-z0-9_]+|secret\.[A-Z0-9_]+)\s*\}\}/',
                function (array $match) use ($application, $settings, $secrets, &$missing): string {
                    $name = $match[1];
                    $value = match (true) {
                        $name === 'app.name' => $application->name,
                        str_starts_with($name, 'input.') => $settings[substr($name, 6)] ?? null,
                        default => $secrets[substr($name, 7)] ?? null,
                    };

                    if (! is_string($value) || $value === '') {
                        $missing = true;

                        return '';
                    }

                    // These raw values are URL-encoded by the installer and sent on stdin,
                    // never interpolated into compose or command arguments.
                    return $value;
                },
                $template,
            );
        }

        return $missing ? null : ['path' => $recipe->afterInstall['path'], 'fields' => $fields];
    }
}
