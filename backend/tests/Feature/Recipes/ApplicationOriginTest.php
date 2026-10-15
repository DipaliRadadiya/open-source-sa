<?php

use App\Models\Application;
use App\Models\User;
use App\Services\Recipes\ApplicationOrigin;

require_once __DIR__.'/../../Support/Recipes/RecipeApiTestSupport.php';

beforeEach(fn () => rc08RecipeSetup());
afterEach(fn () => rc08RecipeCleanup());

it('describes a recipe before consulting its image or stored compose', function () {
    $site = rc03RecipeSite('ghost', ['image' => 'ghost:5-alpine', 'compose' => 'stored recipe compose']);
    $expected = ['kind' => 'recipe', 'recipe' => ['slug' => 'ghost', 'title' => 'Ghost', 'version' => 1, 'deprecated' => false], 'image' => null];
    expect(app(ApplicationOrigin::class)->describe($site))->toBe($expected);
    $this->actingAs(rc08RecipeViewer())->getJson('/api/applications/'.$site->id)->assertOk()->assertJsonPath('application.origin', $expected);
});

it('describes direct images for null empty and whitespace compose', function (?string $compose) {
    $site = rc03RecipeSite('docker', ['image' => 'neosmemo/memos:0.31.0', 'compose' => $compose]);
    expect(app(ApplicationOrigin::class)->describe($site))->toBe(['kind' => 'image', 'recipe' => null, 'image' => 'neosmemo/memos:0.31.0']);
})->with([null, '', " \n\t "]);

it('describes a nonempty Docker compose without advertising an image', function () {
    $site = rc03RecipeSite('docker', ['image' => 'ignored:1', 'compose' => " services:\n  app:\n    image: nginx:1.28\n"]);
    expect(app(ApplicationOrigin::class)->describe($site))->toBe(['kind' => 'compose', 'recipe' => null, 'image' => null]);
});

it('describes native and unknown nonrecipe sites as other', function (string $type) {
    $site = Application::factory()->create(['site_type' => $type]);
    expect(app(ApplicationOrigin::class)->describe($site))->toBe(['kind' => 'other', 'recipe' => null, 'image' => null]);
})->with(['php', 'removed_custom_type']);

it('includes origins on every application list row without secret values', function () {
    rc03RecipeSite('ghost', ['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'private-root-value']]);
    rc03RecipeSite('docker', ['id' => 2, 'slug' => 'site-2', 'image' => 'neosmemo/memos:0.31.0', 'compose' => '']);
    rc03RecipeSite('docker', ['id' => 3, 'slug' => 'site-3', 'compose' => 'services: {}']);
    rc03RecipeSite('php', ['id' => 4, 'slug' => 'site-4', 'serving_profile' => 'php']);
    $response = $this->actingAs(rc08RecipeViewer())->getJson('/api/applications')->assertOk()->assertJsonCount(4, 'applications');
    $origins = collect($response->json('applications'))->keyBy('id');
    expect($origins[1]['origin']['kind'])->toBe('recipe')->and($origins[2]['origin']['kind'])->toBe('image')
        ->and($origins[3]['origin']['kind'])->toBe('compose')->and($origins[4]['origin']['kind'])->toBe('other');
    expect($response->content())->not->toContain('private-root-value');
});

it('retains recipe origin and credentials for existing retired sites without changing their stored compose', function () {
    rc08RecipeFixture(['deprecated' => true, 'version' => 3]);
    $site = rc03RecipeSite('demo_single', ['compose' => 'original installed compose', 'docker_secrets' => ['PASSWORD' => 'existing-value']]);
    $this->actingAs(User::factory()->admin()->create())->getJson('/api/applications/'.$site->id)->assertOk()
        ->assertJsonPath('application.origin', ['kind' => 'recipe', 'recipe' => ['slug' => 'demo_single', 'title' => 'Demo', 'version' => 3, 'deprecated' => true], 'image' => null]);
    $this->getJson('/api/applications/'.$site->id.'/container/secrets')->assertOk()
        ->assertJsonPath('credentials', [['label' => 'password', 'value' => 'existing-value', 'secret' => true]]);
    expect($site->fresh()->compose)->toBe('original installed compose');
});
