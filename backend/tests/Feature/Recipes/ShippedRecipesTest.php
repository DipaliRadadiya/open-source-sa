<?php

use App\Models\Application;
use App\Services\Recipes\Recipe;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Recipes\RecipeRenderer;
use Symfony\Component\Yaml\Yaml;

function rc03AssertRecipeStructure(Recipe $recipe): void
{
    foreach ([null, '1.5'] as $cpu) {
        $site = new Application;
        $site->forceFill([
            'id' => 4242, 'domain' => 'recipe.example.test', 'app_port' => 20101,
            'cpu_limit' => $cpu, 'settings' => ['admin_email' => 'owner@example.test', 'admin_username' => 'owner'],
        ]);
        $secrets = [];
        foreach ($recipe->secrets as $secret) {
            $secrets[$secret['key']] = $secret['complexity'] === 'complex' ? 'Aa1!'.str_repeat('a', 28) : str_repeat('a', 32);
        }
        $root = '/home/owner/site/public_html';
        $compose = app(RecipeRenderer::class)->render($recipe, $site, 'https://recipe.example.test', $secrets, $root);
        $yaml = Yaml::parse($compose);
        $expectedVolumes = array_map(fn ($role) => 'sv-app-4242_'.$role, array_keys($recipe->volumes));
        expect(array_keys($yaml['services']))->toEqualCanonicalizing(array_column($recipe->services, 'name'));
        $seenVolumes = [];
        foreach ($yaml['services'] as $name => $service) {
            expect($service['restart'])->toBe('unless-stopped')->and($service['mem_limit'])->not->toBeEmpty()
                ->and($service['logging']['driver'])->toBe('json-file')->and($service['logging']['options']['max-size'])->not->toBeEmpty();
            if ($name === $recipe->appService()['name']) {
                expect($service['ports'])->toBe(['127.0.0.1:20101:'.$recipe->containerPort]);
                if ($cpu !== null) {
                    expect((string) $service['cpus'])->toBe($cpu);
                } else {
                    expect($service)->not->toHaveKey('cpus');
                }
            } else {
                expect($service)->not->toHaveKey('ports');
            }
            expect(array_intersect(array_keys($service), ['privileged', 'cap_add', 'devices', 'pid', 'ipc', 'userns_mode', 'security_opt', 'network_mode', 'cgroup_parent', 'build']))->toBe([]);
            foreach ($service['volumes'] ?? [] as $mount) {
                $source = explode(':', $mount)[0];
                if (str_starts_with($source, '/')) {
                    expect($source)->toStartWith($root.'/');
                } else {
                    $seenVolumes[] = $source;
                }
            }
        }
        expect(array_values(array_unique($seenVolumes)))->toEqualCanonicalizing($expectedVolumes)
            ->and(array_keys($yaml['volumes'] ?? []))->toEqualCanonicalizing($expectedVolumes);
        foreach ($yaml['volumes'] ?? [] as $volume) {
            expect($volume['external'])->toBeTrue();
        }
        expect($compose)->not->toContain('docker.sock')->not->toContain('{{')->not->toContain("\r")
            ->and(preg_match('/(?<!\$)\$(?!\$)/', $compose))->toBe(0);
    }
}

it('renders and structurally verifies every shipped recipe in both CPU variants', function () {
    config(['recipes.path' => resource_path('recipes')]);
    app(RecipeRegistry::class)->flush();
    foreach (app(RecipeRegistry::class)->all() as $recipe) {
        rc03AssertRecipeStructure($recipe);
    }
    expect(true)->toBeTrue();
});

it('exercises shipped structural assertions with all demo recipes even before real recipes ship', function () {
    config([
        'recipes.path' => base_path('tests/Fixtures/recipes/valid'),
        'recipes.hook_namespaces' => ['App\\Services\\Recipes\\Hooks\\', 'Tests\\Support\\Recipes\\'],
    ]);
    app(RecipeRegistry::class)->flush();
    foreach (app(RecipeRegistry::class)->all() as $recipe) {
        rc03AssertRecipeStructure($recipe);
    }
});
