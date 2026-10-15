<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/../../Support/Recipes/RecipeTestSupport.php';

beforeEach(fn () => rc03RecipeSetup());

it('refuses stored newline injection at render without saving compose', function (string $field, mixed $value, string $type) {
    $site = rc03RecipeSite($type);
    $site->forceFill([$field => $value])->save();
    try {
        rc03RecipeInstall($site);
        $this->fail('Unsafe value was rendered');
    } catch (ProvisioningFailedException $e) {
        expect($e->step)->toBe('compose_write')->and($site->fresh()->compose)->toBeNull();
    }
})->with([
    'domain' => ['domain', "x.test\nprivileged: true", 'demo_single'],
    'memory' => ['memory_limit', "512m\n    privileged: true", 'demo_single'],
    'cpu' => ['cpu_limit', "1\n", 'demo_single'],
    'secret' => ['docker_secrets', ['PASSWORD' => "abcdefgh\nprivileged: true"], 'demo_single'],
    'input' => ['settings', ['admin_email' => "a@b.c\nx: y"], 'demo_claim'],
]);

it('refuses scalar injection even when the template newline backstop cannot catch it', function (string $field, mixed $value, string $type) {
    $site = rc03RecipeSite($type);
    $site->forceFill([$field => $value])->save();
    expect(fn () => rc03RecipeInstall($site))->toThrow(ProvisioningFailedException::class);
    expect($site->fresh()->compose)->toBeNull();
})->with([
    'URL punctuation' => ['domain', 'example.test#injected', 'demo_single'],
    'memory scalar' => ['memory_limit', '512m #unbounded', 'demo_single'],
    'cpu scalar' => ['cpu_limit', '1 #unbounded', 'demo_single'],
    'secret quotes' => ['docker_secrets', ['PASSWORD' => 'abcdefgh" #injected'], 'demo_single'],
    'email quotes' => ['settings', ['admin_email' => 'owner@example.test" #injected'], 'demo_claim'],
]);

it('refuses unsafe image overrides including scalar-only injection', function (string $image) {
    config(['recipes.image_overrides.demo_single.app' => $image]);
    $site = rc03RecipeSite();
    try {
        rc03RecipeInstall($site);
        $this->fail('Unsafe image was accepted');
    } catch (ProvisioningFailedException $e) {
        expect($e->step)->toBe('compose_write')->and($site->fresh()->compose)->toBeNull();
    }
})->with(["evil:1\n  privileged: true", 'evil:1 #injected', 'evil']);

it('logs only the field name and correlation reference when render fails', function () {
    $secret = 'abcdefgh" #DO_NOT_LOG';
    $site = rc03RecipeSite(overrides: ['docker_secrets' => ['PASSWORD' => $secret]]);
    $logger = Mockery::mock(LoggerInterface::class);
    Log::shouldReceive('channel')->with('server-ops')->andReturn($logger);
    $logger->shouldReceive('error')->once()->with('Refused to render a recipe compose file', Mockery::on(function ($context) use ($secret, $site) {
        return $context['field'] === 'secret.PASSWORD'
            && $context['application'] === $site->id
            && $context['op'] === 'recipe_render'
            && ! str_contains(json_encode($context), $secret)
            && isset($context['reference']);
    }));
    expect(fn () => app(DockerAppInstaller::class)->install($site, '/home/owner/site/public_html', []))->toThrow(ProvisioningFailedException::class);
});

it('converts sync render errors to the resync-safe provisioning exception', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $stored = $site->compose;
    $site->forceFill(['memory_limit' => 'unsafe #value'])->save();
    expect(fn () => app(DockerAppInstaller::class)->syncUrl($site, 'https://example.test'))->toThrow(ProvisioningFailedException::class);
    expect($site->fresh()->compose)->toBe($stored);
});
