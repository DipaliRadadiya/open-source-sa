<?php

use App\Models\Application;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Recipes\RecipeRegistry;

require_once __DIR__.'/../../Support/Recipes/RecipeApiTestSupport.php';

beforeEach(fn () => rc08RecipeSetup());
afterEach(fn () => rc08RecipeCleanup());

it('lists every shipped recipe as a JSON list with application view alone', function () {
    $this->actingAs(rc08RecipeViewer());
    $recipes = $this->getJson('/api/recipes')->assertOk()->assertJsonCount(count(app(RecipeRegistry::class)->all()), 'recipes')->json('recipes');
    expect(array_column($recipes, 'name'))->toContain(
        'bookstack', 'chatwoot', 'excalidraw', 'forgejo', 'freshrss', 'ghost', 'gitea', 'glance', 'grafana', 'homepage',
        'ittools', 'matomo', 'mattermost', 'metabase', 'nocodb', 'stirlingpdf', 'vaultwarden', 'wikijs', 'wordpress_container',
        'memos', 'paperless',
    );
    expect(array_is_list($recipes))->toBeTrue();
    foreach ($recipes as $entry) {
        expect($entry['recipe']['slug'])->toBe($entry['name']);
        expect(array_keys($entry['recipe']))->toBe([
            'slug', 'version', 'description', 'services', 'volumes', 'images', 'memory_floor', 'first_run', 'inputs', 'docs_url', 'icon_data_uri',
        ]);
        foreach (['services', 'volumes', 'images', 'inputs'] as $key) {
            expect(array_is_list($entry['recipe'][$key]))->toBeTrue();
        }
    }
    $catalog = $this->getJson('/api/site-types')->assertOk()->json('site_types');
    expect($recipes)->toBe(array_values(array_filter($catalog, fn ($entry) => $entry['recipe'] !== null)));
});

it('returns the same Ghost catalog entry with list-shaped images and volumes', function () {
    $this->actingAs(rc08RecipeViewer());
    $entry = $this->getJson('/api/recipes/ghost')->assertOk()->json('recipe');
    expect($entry['name'])->toBe('ghost')->and($entry['title'])->toBe('Ghost');
    expect($entry['recipe'])->toBe([
        'slug' => 'ghost', 'version' => 1, 'description' => null,
        'services' => [['name' => 'ghost', 'role' => 'app'], ['name' => 'db', 'role' => 'database']],
        'volumes' => [['role' => 'content', 'path' => '/var/lib/ghost/content'], ['role' => 'db', 'path' => '/var/lib/mysql']],
        'images' => [['role' => 'app', 'ref' => 'ghost:5-alpine', 'tested' => ['ghost:5-alpine']], ['role' => 'db', 'ref' => 'mysql:8.0', 'tested' => ['mysql:8.0']]],
        'memory_floor' => null, 'first_run' => ['kind' => 'setup_wizard', 'credential_labels' => []],
        'inputs' => [], 'docs_url' => null, 'icon_data_uri' => null,
    ]);
    $catalog = $this->getJson('/api/site-types')->assertOk()->json('site_types');
    expect($entry)->toBe(collect($catalog)->firstWhere('name', 'ghost'));
    expect(collect($catalog)->firstWhere('name', 'docker')['recipe'])->toBeNull();
});

it('does not expose credential sources or internal recipe settings in the catalog', function () {
    $this->actingAs(rc08RecipeViewer());
    $entry = $this->getJson('/api/recipes/chatwoot')->assertOk()->json('recipe.recipe');
    expect($entry['first_run'])->toBe(['kind' => 'claimed', 'credential_labels' => ['email', 'password']])
        ->and($entry['inputs'])->toBe(['admin_email'])->and($entry['memory_floor'])->toBe('2g');
    foreach (['secrets', 'starter_files', 'hook', 'after_install', 'directory', 'template'] as $key) {
        expect($entry)->not->toHaveKey($key);
    }
    expect(json_encode($entry))->not->toContain('ADMIN_PASSWORD', 'POSTGRES_PASSWORD');
});

it('omits recipes on non Docker servers and refuses their detail route', function () {
    rc08RecipeSetup('lemp');
    $this->actingAs(rc08RecipeViewer())->getJson('/api/recipes')->assertOk()->assertExactJson(['recipes' => []]);
    $this->getJson('/api/recipes/ghost')->assertNotFound();
    foreach ($this->getJson('/api/site-types')->assertOk()->json('site_types') as $entry) {
        expect($entry['recipe'])->toBeNull();
    }
});

it('returns 404 for unknown non recipe and invalid slugs', function (string $slug) {
    $this->actingAs(rc08RecipeViewer())->getJson('/api/recipes/'.$slug)->assertNotFound();
})->with(['nope', 'docker', 'Ghost', 'x', 'bad-slug', str_repeat('x', 41)]);

it('requires authentication for recipe routes', function (string $route) {
    $this->getJson($route)->assertUnauthorized();
})->with(['/api/recipes', '/api/recipes/ghost']);

it('denies recipe routes without application view permission', function (string $route) {
    $this->actingAs(User::factory()->create())->getJson($route)->assertForbidden();
})->with(['/api/recipes', '/api/recipes/ghost']);

it('hides retired recipes in both catalogs and refuses detail and creation', function () {
    rc08RecipeFixture(['deprecated' => true]);
    $manager = app(SiteTypeManager::class);
    expect($manager->unavailable($manager->find('demo_single')))->toBe([
        'code' => SiteTypeManager::BLOCKED_DEPRECATED,
        'reason' => __('application.unavailable.deprecated'), 'runtime' => null,
    ]);
    $this->actingAs(User::factory()->admin()->create());
    $this->getJson('/api/recipes')->assertOk()->assertExactJson(['recipes' => []]);
    $types = $this->getJson('/api/site-types')->assertOk()->json('site_types');
    expect(array_is_list($types))->toBeTrue()->and(array_column($types, 'name'))->not->toContain('demo_single');
    $this->getJson('/api/recipes/demo_single')->assertNotFound();
    $this->postJson('/api/applications', [
        'name' => 'Retired Demo', 'domain' => 'retired.example.test', 'site_type' => 'demo_single', 'generate_system_user' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('site_type')
        ->assertJsonPath('errors.site_type.0', __('application.unavailable.deprecated'));
    expect(Application::count())->toBe(0)->and(app(RecipeRegistry::class)->has('demo_single'))->toBeTrue();
});

it('prioritizes the retirement refusal over a stack refusal and translates it', function (string $locale) {
    rc08RecipeSetup('lemp');
    rc08RecipeFixture(['deprecated' => true]);
    $this->actingAs(User::factory()->admin()->create());
    $response = $this->withHeader('Accept-Language', $locale)->postJson('/api/applications', [
        'name' => 'Retired Demo', 'domain' => 'retired.example.test', 'site_type' => 'demo_single', 'generate_system_user' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('site_type');
    expect($response->json('errors.site_type.0'))->toBe(__('application.unavailable.deprecated', [], $locale))
        ->not->toBe('application.unavailable.deprecated');
    expect(app(SiteTypeManager::class)->unavailable(app(SiteTypeManager::class)->find('demo_single'))['code'])->toBe('deprecated');
})->with(['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi']);

it('localizes recipe descriptions with English fallback and carries optional public metadata', function (string $locale, string $description) {
    $recipe = rc08RecipeFixture([
        'description' => ['en' => 'English description', 'es' => 'Descripción en español'],
        'docs_url' => 'https://example.test/docs', 'memory_floor' => '1g',
    ]);
    $this->actingAs(rc08RecipeViewer())->withHeader('Accept-Language', $locale)->getJson('/api/recipes/demo_single')->assertOk()
        ->assertJsonPath('recipe.recipe.description', $description)
        ->assertJsonPath('recipe.recipe.docs_url', 'https://example.test/docs')
        ->assertJsonPath('recipe.recipe.memory_floor', '1g')
        ->assertJsonPath('recipe.recipe.first_run.credential_labels', ['password'])
        ->assertJsonPath('recipe.recipe.icon_data_uri', $recipe->iconDataUri);
})->with([['es', 'Descripción en español'], ['ja', 'English description']]);

it('keeps the documented Ghost recipe payload identical to the API metadata', function () {
    preg_match('/<!-- rc08-ghost-response -->\s*```json\s*(.*?)\s*```/s', file_get_contents(base_path('API_REFERENCE.md')), $match);
    expect($match)->toHaveKey(1);
    $documented = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    $entry = $this->actingAs(rc08RecipeViewer())->getJson('/api/recipes/ghost')->assertOk()->json('recipe');
    expect($documented['recipe']['recipe'])->toBe($entry['recipe'])
        ->and(array_keys($documented['recipe']))->toBe(array_keys($entry))
        ->and($documented['recipe']['name'])->toBe($entry['name']);
});
