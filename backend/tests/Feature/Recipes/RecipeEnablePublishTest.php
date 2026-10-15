<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Applications\Installers\DockerAppInstaller;

require_once __DIR__.'/../../Support/Recipes/RecipeApiTestSupport.php';

beforeEach(fn () => rc08RecipeSetup());
afterEach(fn () => rc08RecipeCleanup());

it('publishes a disabled recipes changed URL before exposing its real vhost on enable', function () {
    $site = rc03RecipeInstall(rc03RecipeSite('gitea', ['docker_secrets' => []]));
    $site->forceFill(['status' => 'active', 'disabled_at' => now()])->save();
    app(DockerAppInstaller::class)->syncUrl($site, 'https://changed.test');
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldReceive('apply')->once()->withArgs(function ($application, $root) {
        return str_contains($application->compose, 'GITEA__server__ROOT_URL: "https://changed.test"')
            && $root === '/home/owner/site/public_html' && $application->disabled_at !== null;
    });
    app()->instance(ContainerSupervisor::class, $containers);

    $this->actingAs(rc08RecipeViewer('application', manage: true))
        ->postJson('/api/applications/'.$site->id.'/enable')->assertOk()
        ->assertJsonPath('application.is_disabled', false);

    expect($site->fresh()->disabled_at)->toBeNull();
});

it('keeps a recipe disabled when its stored compose cannot be applied on enable', function () {
    $site = rc03RecipeInstall(rc03RecipeSite('gitea', ['docker_secrets' => []]));
    $site->forceFill(['status' => 'active', 'disabled_at' => now()])->save();
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldReceive('apply')->once()->andThrow(new ProvisioningFailedException('compose_write', 'enable-reference'));
    app()->instance(ContainerSupervisor::class, $containers);

    $this->actingAs(rc08RecipeViewer('application', manage: true))
        ->postJson('/api/applications/'.$site->id.'/enable')->assertStatus(500);

    expect($site->fresh()->disabled_at)->not->toBeNull();
});

it('does not publish containers when a viewer is forbidden to enable the recipe', function () {
    $site = rc03RecipeInstall(rc03RecipeSite('gitea', ['docker_secrets' => []]));
    $site->forceFill(['status' => 'active', 'disabled_at' => now()])->save();
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldNotReceive('apply');
    app()->instance(ContainerSupervisor::class, $containers);

    $this->actingAs(rc08RecipeViewer('application'))
        ->postJson('/api/applications/'.$site->id.'/enable')->assertForbidden();

    expect($site->fresh()->disabled_at)->not->toBeNull();
});
