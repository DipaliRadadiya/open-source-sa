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

describe('a refused resume says why (F3)', function () {
    function rc08RefuseEnable(ProvisioningFailedException $why): void
    {
        $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
        $containers->shouldReceive('apply')->once()->andThrow($why);
        app()->instance(ContainerSupervisor::class, $containers);
    }

    it('names the reason the container step gave, keeping code, reference and status', function () {
        $site = rc03RecipeInstall(rc03RecipeSite('gitea', ['docker_secrets' => []]));
        $site->forceFill(['status' => 'active', 'disabled_at' => now()])->save();
        rc08RefuseEnable(new ProvisioningFailedException('container_restarting', 'loop-reference', 'container_restarting'));

        $this->actingAs(rc08RecipeViewer('application', manage: true))
            ->postJson('/api/applications/'.$site->id.'/enable')
            ->assertStatus(500)
            ->assertJsonPath('code', 'server_operation_failed')
            ->assertJsonPath('reference', 'loop-reference')
            ->assertJsonPath('reason', 'container_restarting')
            ->assertJsonPath('message', __('errors/application.availability_failed_because', [
                'reason' => __('application.failure_reason.container_restarting'),
            ]));

        expect($site->fresh()->disabled_at)->not->toBeNull();
    });

    it('answers in the language the user asked for', function () {
        $site = rc03RecipeInstall(rc03RecipeSite('gitea', ['docker_secrets' => []]));
        $site->forceFill(['status' => 'active', 'disabled_at' => now()])->save();
        rc08RefuseEnable(new ProvisioningFailedException('container_restarting', 'loop-reference', 'container_restarting'));

        $message = $this->actingAs(rc08RecipeViewer('application', manage: true))
            ->withHeader('Accept-Language', 'de')
            ->postJson('/api/applications/'.$site->id.'/enable')
            ->assertStatus(500)
            ->json('message');

        expect($message)->toStartWith('Die Anwendung konnte nicht wieder eingeschaltet werden.')
            ->toContain(__('application.failure_reason.container_restarting', [], 'de'));
    });

    it('keeps the old message when the step gave no reason', function () {
        $site = rc03RecipeInstall(rc03RecipeSite('gitea', ['docker_secrets' => []]));
        $site->forceFill(['status' => 'active', 'disabled_at' => now()])->save();
        rc08RefuseEnable(new ProvisioningFailedException('compose_write', 'write-reference'));

        $this->actingAs(rc08RecipeViewer('application', manage: true))
            ->postJson('/api/applications/'.$site->id.'/enable')
            ->assertStatus(500)
            ->assertJsonPath('message', __('errors/application.availability_failed'))
            ->assertJsonMissingPath('reason');
    });

    it('never shows a reason that is not a known code', function () {
        // `compose_invalid` carries sentences, not a code; an unknown code would
        // render as its raw lang key. Neither reaches the user.
        $site = rc03RecipeInstall(rc03RecipeSite('gitea', ['docker_secrets' => []]));
        $site->forceFill(['status' => 'active', 'disabled_at' => now()])->save();
        rc08RefuseEnable(new ProvisioningFailedException('compose_invalid', '', 'services.app.image is required'));

        $this->actingAs(rc08RecipeViewer('application', manage: true))
            ->postJson('/api/applications/'.$site->id.'/enable')
            ->assertStatus(500)
            ->assertJsonPath('message', __('errors/application.availability_failed'))
            ->assertJsonMissingPath('reason');
    });
});
