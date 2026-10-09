<?php

namespace App\Services\Recipes;

use App\Services\Recipes\Exceptions\InvalidRecipeException;
use App\Services\Recipes\Hooks\RecipeHook;

final class RecipeLoader
{
    public function load(string $directory): Recipe
    {
        $slug = basename($directory);
        $fail = fn (string $code) => throw new InvalidRecipeException($slug, $code);
        if (! is_file($directory.'/recipe.json')) {
            $fail('recipe_json_missing');
        }
        try {
            $data = json_decode(file_get_contents($directory.'/recipe.json'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $fail('schema');
        }
        if (! is_array($data)) {
            $fail('schema');
        }
        RecipeSchema::check($data, $directory);
        if (! is_file($directory.'/compose.yml.tpl')) {
            $fail('template_syntax');
        }
        $template = RecipeTemplate::fromString(file_get_contents($directory.'/compose.yml.tpl'), $slug);
        RecipeTemplateLinter::lint($data, $template);
        $starter = [];
        foreach ($data['starter_files'] ?? [] as $file) {
            $starter[$file['path']] = file_get_contents($directory.'/'.$file['source']);
        }
        $icon = null;
        if (is_file($directory.'/icon.svg')) {
            $svg = file_get_contents($directory.'/icon.svg');
            if (strlen($svg) > 32768 || ! preg_match('/^(<svg|<\?xml)/', $svg) || preg_match('/<script|\bon[a-z]+\s*=|javascript:|<foreignObject|<iframe|<embed|(?:xlink:)?href\s*=\s*["\']?(?!#)/i', $svg)) {
                $fail('icon_invalid');
            }
            $icon = 'data:image/svg+xml;base64,'.base64_encode($svg);
        }
        $hook = $data['hook'] ?? null;
        if ($hook !== null) {
            $allowed = false;
            foreach (config('recipes.hook_namespaces') as $namespace) {
                if (str_starts_with($hook, $namespace)) {
                    $allowed = true;
                }
            }
            if (! $allowed || ! class_exists($hook) || ! is_subclass_of($hook, RecipeHook::class)) {
                $fail('hook_invalid');
            }
        }

        return new Recipe(
            schema: $data['schema'],
            slug: $slug,
            version: $data['version'],
            title: $data['title'],
            taglines: $data['tagline'],
            descriptions: $data['description'] ?? [],
            category: $data['category'],
            icon: $data['icon'],
            popular: $data['popular'] ?? false,
            catalogOrder: $data['catalog_order'],
            deprecated: $data['deprecated'] ?? false,
            docsUrl: $data['docs_url'] ?? null,
            images: $data['images'],
            services: $data['services'],
            containerPort: $data['container_port'],
            memoryFloor: $data['memory_floor'] ?? null,
            volumes: $data['volumes'] ?? [],
            secrets: $data['secrets'] ?? [],
            urlEnvKey: $data['url_env_key'] ?? null,
            inputs: $data['inputs'] ?? [],
            starterFiles: $starter,
            firstRun: $data['first_run'],
            afterInstall: $data['after_install'] ?? null,
            hook: $hook,
            notes: $data['notes'] ?? [],
            iconDataUri: $icon,
            template: $template,
            directory: $directory,
        );
    }
}
