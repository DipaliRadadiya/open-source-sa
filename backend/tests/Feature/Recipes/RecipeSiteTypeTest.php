<?php

use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\Types\AbstractDockerAppType;
use App\Services\Applications\Types\GhostSiteType;
use App\Services\Applications\Types\RecipeSiteType;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use Illuminate\Support\Facades\Validator;

require_once __DIR__.'/../../Support/Recipes/RecipeTestSupport.php';

beforeEach(fn () => rc03RecipeSetup());

it('inserts recipes after Docker in registry order and supplies the one-click installer', function () {
    $manager = app(SiteTypeManager::class);
    $names = $manager->names();
    $docker = array_search('docker', $names, true);
    expect(array_slice($names, $docker + 1, 4))->toBe(['demo_multi', 'demo_single', 'demo_claim', 'demo_hook']);
    $entry = collect($manager->catalog())->firstWhere('name', 'demo_single');
    expect($entry)->not->toBeNull()->and($entry['available'])->toBeTrue()
        ->and($entry['has_installer'])->toBeTrue()->and($entry['method'])->toBe('one_click');
    expect(app(InstallerManager::class)->installerForType('demo_single'))->toBeInstanceOf(DockerAppInstaller::class)
        ->and(app(InstallerManager::class)->hasInstaller('demo_single'))->toBeTrue()
        ->and(app(InstallerManager::class)->hasInstaller('missing'))->toBeFalse();
    $type = $manager->find('demo_single');
    expect($type)->toBeInstanceOf(RecipeSiteType::class)->toBeInstanceOf(AbstractDockerAppType::class)
        ->and($type->containerPort())->toBe(80)->and($type->volumeRoles())->toBe(['data' => '/data'])
        ->and($type->generatedSecrets())->toBe(['PASSWORD'])->and($type->servingProfile())->toBe('docker')
        ->and(fn () => $type->composeTemplate())->toThrow(LogicException::class);
});

it('refuses recipe types on a non-Docker stack', function () {
    rc03RecipeSetup('lemp');
    $manager = app(SiteTypeManager::class);
    expect(collect($manager->catalog())->pluck('name')->all())->not->toContain('demo_single')
        ->and($manager->unavailable($manager->find('demo_single'))['code'])->toBe(SiteTypeManager::BLOCKED_STACK);
});

it('adds input fields and rules without losing sizing fields', function () {
    $type = app(SiteTypeManager::class)->find('demo_claim');
    $fields = collect($type->fields())->keyBy('name');
    expect($fields->keys()->all())->toBe(['memory_limit', 'cpu_limit', 'admin_email'])
        ->and($fields['admin_email']['type'])->toBe('email')->and($fields['admin_email']['required'])->toBeTrue();
    expect(Validator::make([], $type->rules())->fails())->toBeTrue()
        ->and(Validator::make(['admin_email' => 'not-an-email'], $type->rules())->fails())->toBeTrue()
        ->and(Validator::make(['admin_email' => 'owner@example.test'], $type->rules())->passes())->toBeTrue();
});

it('rejects duplicate class and recipe names before building the catalog', function () {
    $root = storage_path('framework/testing/rc03-duplicate-'.bin2hex(random_bytes(6)));
    mkdir($root.'/ghost', 0777, true);
    try {
        $source = base_path('tests/Fixtures/recipes/valid/demo_single');
        $data = json_decode(file_get_contents($source.'/recipe.json'), true, flags: JSON_THROW_ON_ERROR);
        $data['slug'] = 'ghost';
        file_put_contents($root.'/ghost/recipe.json', json_encode($data, JSON_THROW_ON_ERROR));
        copy($source.'/compose.yml.tpl', $root.'/ghost/compose.yml.tpl');
        config([
            'recipes.path' => $root,
            'server.site_types' => [...config('server.site_types'), GhostSiteType::class],
        ]);
        app(RecipeRegistry::class)->flush();
        expect(fn () => app(SiteTypeManager::class)->all())->toThrow(LogicException::class, 'Duplicate site type names');
    } finally {
        unlink($root.'/ghost/recipe.json');
        unlink($root.'/ghost/compose.yml.tpl');
        rmdir($root.'/ghost');
        rmdir($root);
    }
});
