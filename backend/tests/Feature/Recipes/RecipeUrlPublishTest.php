<?php

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/../../Support/Recipes/RecipeTestSupport.php';

beforeEach(fn () => rc03RecipeSetup());

function rc13RecipeDisk(string $contents): void
{
    $files = Mockery::mock(ManagedFile::class);
    $files->shouldReceive('get')->with('/home/owner/site/public_html/compose.yml', Mockery::type('array'))
        ->andReturn(new ServerOpsResult(true, 'disk-reference', Process::result(output: $contents), answered: true));
    app()->instance(ManagedFile::class, $files);
}

it('publishes and applies a changed canonical URL for an active recipe without rotating secrets', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->update(['status' => ApplicationStatus::Active]);
    rc13RecipeDisk($site->compose);
    $before = $site->docker_secrets;
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldReceive('apply')->once()->withArgs(function ($application, $root) use ($site) {
        expect($application->fresh()->compose)->toBe($application->compose)
            ->and($application->compose)->toContain('APP_URL: https://changed.test')
            ->and($root)->toBe('/home/owner/site/public_html')
            ->and($application->id)->toBe($site->id);

        return true;
    });
    app()->instance(ContainerSupervisor::class, $containers);

    app(DockerAppInstaller::class)->syncUrl($site, 'https://changed.test');

    expect($site->fresh()->docker_secrets)->toBe($before);
});

it('does not restart an active recipe whose canonical compose is already identical', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->update(['status' => ApplicationStatus::Active]);
    rc13RecipeDisk($site->compose);
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldNotReceive('apply');
    app()->instance(ContainerSupervisor::class, $containers);
    $before = $site->compose;

    app(DockerAppInstaller::class)->syncUrl($site, 'https://example.test');

    expect($site->fresh()->compose)->toBe($before);
});

it('does not start an unprovisioned or disabled recipe while updating its stored URL', function (string $status, bool $disabled) {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->forceFill(['status' => $status, 'disabled_at' => $disabled ? now() : null])->save();
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldNotReceive('apply');
    app()->instance(ContainerSupervisor::class, $containers);

    app(DockerAppInstaller::class)->syncUrl($site, 'https://changed.test');

    expect($site->fresh()->compose)->toContain('APP_URL: https://changed.test');
})->with([['pending', false], ['provisioning', false], ['failed', false], ['active', true]]);

it('restores stored and applied compose when the changed URL cannot be published', function (bool $rollbackFails) {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->update(['status' => ApplicationStatus::Active]);
    rc13RecipeDisk($site->compose);
    $before = $site->compose;
    $secrets = $site->docker_secrets;
    $failure = new ProvisioningFailedException('compose_write', 'original-reference');
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldReceive('apply')->once()->ordered()->andThrow($failure);
    $restore = $containers->shouldReceive('apply')->once()->ordered()->withArgs(function ($application, $root) use ($before) {
        expect($application->compose)->toBe($before)->and($application->fresh()->compose)->toBe($before)
            ->and($root)->toBe('/home/owner/site/public_html');

        return true;
    });
    if ($rollbackFails) {
        $restore->andThrow(new ProvisioningFailedException('compose_up', 'rollback-reference'));
    }
    app()->instance(ContainerSupervisor::class, $containers);

    try {
        app(DockerAppInstaller::class)->syncUrl($site, 'https://changed.test');
        test()->fail('The failed publish must not be reported as a successful URL transition.');
    } catch (ProvisioningFailedException $exception) {
        expect($exception)->toBe($failure);
    }

    expect($site->fresh()->compose)->toBe($before)->and($site->fresh()->docker_secrets)->toBe($secrets);
})->with([false, true]);

it('repairs an active recipe whose database URL is canonical but published compose is stale', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->update(['status' => ApplicationStatus::Active]);
    $expected = $site->compose;
    rc13RecipeDisk(str_replace('https://example.test', 'http://old.test', $expected));
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldReceive('apply')->once()->withArgs(function ($application, $root) use ($expected) {
        return $application->compose === $expected && $root === '/home/owner/site/public_html';
    });
    app()->instance(ContainerSupervisor::class, $containers);

    app(DockerAppInstaller::class)->syncUrl($site, 'https://example.test');

    expect($site->fresh()->compose)->toBe($expected);
});

it('refuses an active URL transition if the existing compose cannot be inspected', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->update(['status' => ApplicationStatus::Active]);
    $before = $site->compose;
    $files = Mockery::mock(ManagedFile::class);
    $files->shouldReceive('get')->andReturn(new ServerOpsResult(false, 'read-denied'));
    app()->instance(ManagedFile::class, $files);
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldNotReceive('apply');
    app()->instance(ContainerSupervisor::class, $containers);

    expect(fn () => app(DockerAppInstaller::class)->syncUrl($site, 'https://changed.test'))
        ->toThrow(ProvisioningFailedException::class);
    expect($site->fresh()->compose)->toBe($before);
});

it('restores actual stale disk bytes and the independent prior DB after a failed drift repair', function () {
    $site = rc03RecipeInstall(rc03RecipeSite());
    $site->update(['status' => ApplicationStatus::Active]);
    $previous = $site->compose;
    $published = str_replace('https://example.test', 'http://old.test', $previous);
    rc13RecipeDisk($published);
    $failure = new ProvisioningFailedException('compose_up', 'original-drift-reference');
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $containers->shouldReceive('apply')->once()->ordered()->andThrow($failure);
    $containers->shouldReceive('apply')->once()->ordered()->withArgs(function ($application, $root) use ($published) {
        expect($application->compose)->toBe($published)->and($application->fresh()->compose)->toBe($published);

        return $root === '/home/owner/site/public_html';
    });
    app()->instance(ContainerSupervisor::class, $containers);

    try {
        app(DockerAppInstaller::class)->syncUrl($site, 'https://example.test');
        test()->fail('An unsuccessful drift repair must not be reported as success.');
    } catch (ProvisioningFailedException $exception) {
        expect($exception)->toBe($failure);
    }

    expect($site->fresh()->compose)->toBe($previous);
});
