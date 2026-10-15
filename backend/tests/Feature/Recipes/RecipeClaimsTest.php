<?php

use App\Services\Applications\SiteTypeManager;
use App\Services\Recipes\RecipeClaims;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/../../Support/Recipes/RecipeTestSupport.php';

beforeEach(fn () => rc03RecipeSetup());

it('builds a declarative claim with raw form values and refuses incomplete claims', function () {
    $site = rc03RecipeInstall(rc03RecipeSite('demo_claim'));
    $recipe = app(RecipeRegistry::class)->find('demo_claim');
    $claim = app(RecipeClaims::class)->for($recipe, $site);
    expect($claim)->toBe(['path' => '/setup', 'fields' => [
        'name' => 'Recipe Owner & Team', 'email' => 'owner@example.test', 'password' => $site->docker_secrets['ADMIN_PASSWORD'],
    ]])->and(app(SiteTypeManager::class)->find('demo_claim')->firstRunClaim($site))->toBe($claim);
    $site->settings = [];
    expect(app(RecipeClaims::class)->for($recipe, $site))->toBeNull();
    $site->settings = ['admin_email' => 'owner@example.test'];
    $site->docker_secrets = [];
    expect(app(RecipeClaims::class)->for($recipe, $site))->toBeNull()
        ->and(app(RecipeClaims::class)->for(app(RecipeRegistry::class)->find('demo_single'), $site))->toBeNull();
});

it('uses an allowlisted hook for a recipe first-run claim', function () {
    $site = rc03RecipeSite('demo_hook');
    expect(app(SiteTypeManager::class)->find('demo_hook')->firstRunClaim($site))
        ->toBe(['path' => '/hook-setup', 'fields' => ['owner' => 'fixed-owner']]);
});

it('posts the URL-encoded claim on stdin and never in argv', function () {
    $site = rc03RecipeInstall(rc03RecipeSite('demo_claim'));
    $posted = null;
    $body = null;
    $checks = 0;
    Process::fake(function ($process) use (&$posted, &$body, &$checks) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        if (($args[0] ?? '') !== 'curl') {
            return Process::result();
        }
        if (in_array('POST', $args, true)) {
            $posted = $args;
            $body = (string) $process->input;

            return Process::result();
        }
        $checks++;

        return Process::result(output: $posted === null ? '200' : '302');
    });
    app(DockerAppInstaller::class)->afterStart($site, '/home/owner/site/public_html');
    expect($checks)->toBeGreaterThanOrEqual(2)->and($posted)->not->toBeNull();
    $argv = implode(' ', $posted);
    expect($argv)->toContain('/setup')->toContain('--data-binary')->not->toContain($site->docker_secrets['ADMIN_PASSWORD'])
        ->and($body)->toBe(http_build_query([
            'name' => 'Recipe Owner & Team', 'email' => 'owner@example.test', 'password' => $site->docker_secrets['ADMIN_PASSWORD'],
        ]));
});
