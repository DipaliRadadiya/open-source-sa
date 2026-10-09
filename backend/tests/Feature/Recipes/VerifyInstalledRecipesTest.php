<?php

use App\Services\Server\Applications\Installers\DockerAppInstaller;

require_once __DIR__.'/../../Support/Recipes/RecipeTestSupport.php';

beforeEach(fn () => rc03RecipeSetup());

it('prints MATCH for an installed fixture without making writes', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->forceFill(['compose' => app(DockerAppInstaller::class)->renderFor($site, $site->url())])->save();
    $before = $site->fresh()->getRawOriginal();
    $this->artisan('recipes:verify-installed', ['--site' => $site->id])->expectsOutputToContain('MATCH')->assertExitCode(0);
    expect($site->fresh()->getRawOriginal())->toBe($before);
});

it('prints DIFFER with a line number and exits nonzero without saving', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->forceFill(['compose' => 'tampered'])->save();
    $this->artisan('recipes:verify-installed', ['--site' => $site->id])->expectsOutputToContain('DIFFER first diff at line 1')->assertExitCode(1);
    expect($site->fresh()->compose)->toBe('tampered');
});

it('classifies image-only drift separately and reports missing secrets as skipped', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->forceFill(['compose' => app(DockerAppInstaller::class)->renderFor($site, $site->url())])->save();
    config(['recipes.image_overrides.demo_single.app' => 'ghost:5.100']);
    $this->artisan('recipes:verify-installed', ['--site' => $site->id])->expectsOutputToContain('MATCH_EXCEPT_IMAGE')->assertExitCode(0);
    $site->forceFill(['docker_secrets' => []])->save();
    $this->artisan('recipes:verify-installed', ['--site' => $site->id])->expectsOutputToContain('SKIPPED')->assertExitCode(0);
});

it('ignores generic Docker and non-Docker applications', function () {
    rc03RecipeSite('docker');
    rc03RecipeSite('wordpress', ['id' => 2, 'slug' => 'native', 'serving_profile' => 'php']);
    $this->artisan('recipes:verify-installed')->doesntExpectOutputToContain('MATCH')->doesntExpectOutputToContain('DIFFER')->assertExitCode(0);
});
