<?php

use App\Services\Recipes\Exceptions\RecipeRenderException;
use App\Services\Recipes\Recipe;
use App\Services\Recipes\RecipeContext;
use App\Services\Recipes\RecipeLoader;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Recipes\RecipeRenderer;
use App\Services\Recipes\RecipeTemplate;
use App\Services\Recipes\RecipeTemplateLinter;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/../../Support/Recipes/RecipeTestSupport.php';

beforeEach(fn () => rc03RecipeSetup());

it('installs exact fixture bytes with and without cpu', function (?string $cpu, string $file) {
    $site = rc03RecipeInstall(rc03RecipeSite(overrides: ['cpu_limit' => $cpu]));
    expect($site->compose)->toBe(file_get_contents(base_path('tests/Fixtures/recipes/valid/demo_single/expected-'.$file.'.yml')))
        ->and($site->container_port)->toBe(80)->and($site->volume_mounts)->toBe([['volume' => 'sv-app-1_data', 'path' => '/data']])
        ->and(app(ContainerSupervisor::class)->panelRendered($site))->toBeTrue();
})->with([[null, 'default'], ['1.5', 'cpu']]);

it('generates and preserves secrets across retry installs including complex panel-only credentials', function () {
    $site = rc03RecipeInstall(rc03RecipeSite('demo_claim', ['docker_secrets' => null]));
    $secrets = $site->docker_secrets;
    expect($secrets['PASSWORD'])->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($secrets['ADMIN_PASSWORD'])->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/[0-9]/')->toMatch('/[!@#%^*_=+\-]/')
        ->and($site->compose)->not->toContain($secrets['ADMIN_PASSWORD']);
    expect(rc03RecipeInstall($site)->docker_secrets)->toBe($secrets);
});

it('writes starter bytes and binds the directory under the document root', function () {
    $site = rc03RecipeInstall(rc03RecipeSite('demo_multi'));
    expect($site->compose)->toContain('/home/owner/site/public_html/app/config:/app/config');
    Process::assertRan(function ($process) {
        return in_array('tee', $process->command, true)
            && in_array('/home/owner/site/public_html/app/config/demo.yml', $process->command, true)
            && $process->input === "demo: true\n";
    });
});

it('renders a sync URL without saving and only syncs URL-aware types', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $stored = DB::table('applications')->find($site->id)->compose;
    $rendered = app(DockerAppInstaller::class)->renderFor($site, 'http://example.test');
    expect($rendered)->toContain('APP_URL: http://example.test')
        ->and(DB::table('applications')->find($site->id)->compose)->toBe($stored);
    app(DockerAppInstaller::class)->syncUrl($site, 'http://example.test');
    expect($site->fresh()->compose)->toBe($rendered);
    $legacy = rc03RecipeSite('vaultwarden', ['id' => 2, 'slug' => 'other', 'docker_secrets' => []]);
    $legacy = rc03RecipeInstall($legacy);
    app(DockerAppInstaller::class)->syncUrl($legacy, 'http://changed.test');
    expect($legacy->fresh()->compose)->toBe($legacy->compose)
        ->and(app(DockerAppInstaller::class)->renderFor(rc03RecipeSite(overrides: ['id' => 3, 'slug' => 'empty', 'docker_secrets' => []]), 'https://example.test'))->toBeNull();
});

it('builds only declared input and secret context with image overrides and sizing precedence', function () {
    $site = rc03RecipeSite(overrides: ['settings' => ['admin_email' => 'ignored@example.test', 'other' => 'ignored'], 'memory_limit' => '640m', 'cpu_limit' => '1.5']);
    $recipe = app(RecipeLoader::class)->load(base_path('tests/Fixtures/recipes/valid/demo_single'));
    config(['recipes.image_overrides.demo_single.app' => 'ghost:5.100']);
    $context = app(RecipeContext::class)->build($recipe, $site, 'https://example.test', ['PASSWORD' => str_repeat('a', 32), 'EXTRA' => 'ignored'], '/home/owner/site/public_html/');
    expect($context)->toHaveKeys(['project', 'app_port', 'container_port', 'image.app', 'memory_limit', 'cpu_limit', 'secret.PASSWORD', 'volume.data'])
        ->and($context['memory_limit'])->toBe('640m')->and($context['image.app'])->toBe('ghost:5.100')
        ->and($context['site_root'])->toBe('/home/owner/site/public_html')
        ->and($context)->not->toHaveKeys(['secret.EXTRA', 'input.admin_email', 'input.other']);
    expect(fn () => app(RecipeContext::class)->build($recipe, $site, 'https://example.test', [], '/home/owner/site/public_html'))
        ->toThrow(RecipeRenderException::class);
    $site->memory_limit = null;
    // A readonly recipe is never mutated: construct a fresh value object from its public metadata.
    $arguments = get_object_vars($recipe);
    $arguments['memoryFloor'] = '2g';
    $withFloor = new Recipe(...$arguments);
    expect(app(RecipeContext::class)->build($withFloor, $site, 'https://example.test', $site->docker_secrets, '/home/owner/site/public_html')['memory_limit'])->toBe('2g');
});

it('permits omitted optional inputs only when an inactive conditional prevents substitution', function () {
    $site = rc03RecipeInstall(rc03RecipeSite('demo_claim'));
    $original = app(RecipeRegistry::class)->find('demo_claim');
    $args = get_object_vars($original);
    $args['inputs'][0]['required'] = false;
    $source = str_replace(
        '      ADMIN_EMAIL: {{ input.admin_email }}',
        "{{#if input.admin_email}}\n      ADMIN_EMAIL: {{ input.admin_email }}\n{{/if}}",
        $original->template->source(),
    );
    $args['template'] = RecipeTemplate::fromString($source, $original->slug);
    $optional = new Recipe(...$args);
    $metadata = json_decode(file_get_contents($original->directory.'/recipe.json'), true, flags: JSON_THROW_ON_ERROR);
    $metadata['inputs'][0]['required'] = false;
    RecipeTemplateLinter::lint($metadata, $optional->template);
    $renderer = app(RecipeRenderer::class);
    foreach ([[], ['admin_email' => null], ['admin_email' => '']] as $settings) {
        $site->settings = $settings;
        expect($renderer->render($optional, $site, 'https://example.test', $site->docker_secrets, '/home/owner/site/public_html'))
            ->not->toContain('ADMIN_EMAIL:');
    }
    $site->settings = ['admin_email' => 'owner@example.test'];
    expect($renderer->render($optional, $site, 'https://example.test', $site->docker_secrets, '/home/owner/site/public_html'))
        ->toContain('ADMIN_EMAIL: owner@example.test');
    $site->settings = ['admin_email' => 'owner@example.test" #injected'];
    expect(fn () => $renderer->render($optional, $site, 'https://example.test', $site->docker_secrets, '/home/owner/site/public_html'))
        ->toThrow(RecipeRenderException::class);
    $site->settings = [];
    // Nullable metadata cannot make an unconditional empty scalar safe.
    $args['template'] = $original->template;
    expect(fn () => $renderer->render(new Recipe(...$args), $site, 'https://example.test', $site->docker_secrets, '/home/owner/site/public_html'))
        ->toThrow(RecipeRenderException::class);
    // Required input stays required even inside an inactive conditional.
    $args['template'] = $optional->template;
    $args['inputs'][0]['required'] = true;
    expect(fn () => $renderer->render(new Recipe(...$args), $site, 'https://example.test', $site->docker_secrets, '/home/owner/site/public_html'))
        ->toThrow(RecipeRenderException::class);
});
