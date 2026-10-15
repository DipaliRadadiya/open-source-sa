<?php

namespace App\Services\Recipes;

final class RecipeRegistry
{
    private ?array $recipes = null;

    public function __construct(private RecipeLoader $loader) {}

    public function path(): string
    {
        return config('recipes.path', resource_path('recipes'));
    }

    public function flush(): void
    {
        $this->recipes = null;
    }

    public function all(): array
    {
        if ($this->recipes !== null) {
            return $this->recipes;
        }
        $recipes = [];
        foreach (glob($this->path().'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (str_starts_with(basename($directory), '.')) {
                continue;
            }
            $recipe = $this->loader->load($directory);
            $recipes[$recipe->slug] = $recipe;
        }
        uasort($recipes, fn ($a, $b) => [$a->catalogOrder, $a->slug] <=> [$b->catalogOrder, $b->slug]);

        return $this->recipes = $recipes;
    }

    public function find(string $slug): ?Recipe
    {
        return $this->all()[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return $this->find($slug) !== null;
    }
}
