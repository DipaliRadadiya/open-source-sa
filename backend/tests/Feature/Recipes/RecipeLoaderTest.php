<?php

use App\Services\Recipes\Exceptions\InvalidRecipeException;
use App\Services\Recipes\RecipeLoader;

it('loads every valid recipe and exposes ordered metadata', function ($slug) {
    $recipe = app(RecipeLoader::class)->load(base_path('tests/Fixtures/recipes/valid/'.$slug));
    expect($recipe->slug)->toBe($slug)->and($recipe->tagline('fr'))->toBe('Demo application')->and($recipe->description('fr'))->toBeNull()->and($recipe->appService()['role'])->toBe('app');
    if ($slug === 'demo_multi') {
        expect($recipe->starterFiles)->toBe(['/app/config/demo.yml' => "demo: true\n"])->and($recipe->iconDataUri)->toStartWith('data:image/svg+xml;base64,');
    }
    if ($slug === 'demo_claim') {
        expect($recipe->panelOnlySecretKeys())->toBe(['ADMIN_PASSWORD'])->and($recipe->complexSecretKeys())->toBe(['ADMIN_PASSWORD']);
    }
})->with(['demo_single', 'demo_multi', 'demo_claim']);
it('refuses each invalid fixture with its precise error code', function ($code) {
    try {
        app(RecipeLoader::class)->load(base_path('tests/Fixtures/recipes/invalid/'.$code));
        $this->fail('Invalid recipe accepted');
    } catch (InvalidRecipeException $e) {
        expect($e->code)->toBe($code);
    }
})->with(array_map('basename', glob(__DIR__.'/../../Fixtures/recipes/invalid/*', GLOB_ONLYDIR)));
it('matches handwritten output bytes with and without cpu', function () {
    $recipe = app(RecipeLoader::class)->load(base_path('tests/Fixtures/recipes/valid/demo_single'));
    foreach ([null => 'default', '1.5' => 'cpu'] as $cpu => $file) {
        $values = ['project' => 'sv-app-1', 'app_port' => 20001, 'container_port' => 80, 'image.app' => 'ghost:5-alpine', 'memory_limit' => '512m', 'cpu_limit' => $cpu === '' ? null : $cpu, 'url' => 'https://example.test', 'secret.PASSWORD' => str_repeat('a', 32), 'volume.data' => 'sv-app-1_data'];
        expect($recipe->template->render($values))->toBe(file_get_contents($recipe->directory.'/expected-'.$file.'.yml'));
    }
});
