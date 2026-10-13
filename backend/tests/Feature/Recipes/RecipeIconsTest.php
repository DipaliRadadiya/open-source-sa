<?php

use App\Services\Recipes\RecipeRegistry;

require_once __DIR__.'/../../Support/Recipes/RecipeApiTestSupport.php';

beforeEach(fn () => rc08RecipeSetup());
afterEach(fn () => rc08RecipeCleanup());

it('loads a safe vector icon for every shipped recipe', function () {
    $recipes = app(RecipeRegistry::class)->all();
    expect(array_keys($recipes))->toEqualCanonicalizing([
        'bookstack', 'chatwoot', 'excalidraw', 'forgejo', 'freshrss', 'ghost', 'gitea', 'glance', 'grafana', 'homepage',
        'ittools', 'matomo', 'mattermost', 'memos', 'metabase', 'nocodb', 'paperless', 'stirlingpdf', 'vaultwarden', 'wikijs', 'wordpress_container',
        'langflow', 'umami', 'odoo', 'immich', 'twenty', 'affine', 'penpot', 'openclaw', 'dify', 'codeserver', 'directus', 'docuseal', 'listmonk', 'keycloak', 'infisical', 'litellm', 'librechat', 'activepieces', 'appsmith', 'docmost', 'siyuan', 'joplin', 'karakeep', 'meilisearch', 'hasura', 'cyberchef', 'cloudreve', 'actual', 'plausible', 'snipeit', 'invoiceninja',
    ]);

    foreach ($recipes as $recipe) {
        $svg = file_get_contents(resource_path('recipes/'.$recipe->slug.'/icon.svg'));
        expect($recipe->iconDataUri)->toBe('data:image/svg+xml;base64,'.base64_encode($svg))
            ->and(strlen($svg))->toBeLessThanOrEqual(32768)
            ->and(preg_match('/^(<svg|<\?xml)/', $svg))->toBe(1)
            ->and(preg_match('/<script|\bon[a-z]+\s*=|javascript:|<foreignObject|<iframe|<embed|<image|(?:xlink:)?href\s*=\s*["\']?(?!#)/i', $svg))->toBe(0);
        $document = new DOMDocument;
        expect($document->loadXML($svg, LIBXML_NONET))->toBeTrue()
            ->and($document->documentElement->localName)->toBe('svg');
    }

    expect(file_get_contents(resource_path('recipes/wordpress_container/icon.svg')))
        ->toBe(file_get_contents(base_path('../frontend/public/site-types/wordpress.svg')));
});

it('exposes each shipped icon unchanged in both authenticated recipe catalogs', function () {
    $this->actingAs(rc08RecipeViewer());
    $recipes = app(RecipeRegistry::class)->all();
    $entries = $this->getJson('/api/recipes')->assertOk()->assertJsonCount(count(app(RecipeRegistry::class)->all()), 'recipes')->json('recipes');
    $siteTypes = collect($this->getJson('/api/site-types')->assertOk()->json('site_types'));

    foreach ($entries as $entry) {
        $slug = $entry['name'];
        expect($entry['recipe']['icon_data_uri'])->toBeString()->toStartWith('data:image/svg+xml;base64,')
            ->toBe($recipes[$slug]->iconDataUri)
            ->and($siteTypes->firstWhere('name', $slug)['recipe']['icon_data_uri'])->toBe($recipes[$slug]->iconDataUri);
    }
});
