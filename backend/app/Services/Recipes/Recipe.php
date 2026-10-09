<?php

namespace App\Services\Recipes;

final readonly class Recipe
{
    public function __construct(
        public int $schema,
        public string $slug,
        public int $version,
        public string $title,
        public array $taglines,
        public array $descriptions,
        public string $category,
        public string $icon,
        public bool $popular,
        public int $catalogOrder,
        public bool $deprecated,
        public ?string $docsUrl,
        public array $images,
        public array $services,
        public int $containerPort,
        public ?string $memoryFloor,
        public array $volumes,
        public array $secrets,
        public ?string $urlEnvKey,
        public array $inputs,
        public array $starterFiles,
        public array $firstRun,
        public ?array $afterInstall,
        public ?string $hook,
        public array $notes,
        public ?string $iconDataUri,
        public RecipeTemplate $template,
        public string $directory,
    ) {}

    public function tagline(string $locale): string
    {
        return $this->taglines[$locale] ?? $this->taglines['en'];
    }

    public function description(string $locale): ?string
    {
        return $this->descriptions[$locale] ?? $this->descriptions['en'] ?? null;
    }

    public function generatedSecretKeys(): array
    {
        return array_column($this->secrets, 'key');
    }

    public function complexSecretKeys(): array
    {
        return array_column(array_filter($this->secrets, fn ($s) => $s['complexity'] === 'complex'), 'key');
    }

    public function panelOnlySecretKeys(): array
    {
        return array_column(array_filter($this->secrets, fn ($s) => $s['panel_only']), 'key');
    }

    public function appService(): array
    {
        return array_values(array_filter($this->services, fn ($s) => $s['role'] === 'app'))[0];
    }
}
