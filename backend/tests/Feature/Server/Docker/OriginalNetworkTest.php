<?php

use App\Actions\Server\Application\UpdateContainerSettings;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\ServerOpsResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Moving a container site to another network leaves the one Compose made for it
 * at create behind for good (BUG-5): `compose up` stops using `<project>_default`
 * and never removes it. It is released as soon as the move has been applied —
 * and only when it is provably the site's own and unused.
 */
beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $user = SystemUser::create(['username' => 'mover', 'home_path' => '/home/mover']);

    $this->site = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'mover', 'slug' => 'mover', 'domain' => 'mover.test',
        'web_root' => 'public_html', 'site_type' => 'docker', 'serving_profile' => 'docker',
        'status' => 'active', 'image' => 'nginx:1.27-alpine', 'container_port' => 80, 'app_port' => 20300,
    ]);

    $this->default = "sv-app-{$this->site->id}_default";
    $this->ran = [];
});

/**
 * @param  bool  $applies  whether the container comes back on the new network.
 * @param  bool  $removes  whether Docker agrees to remove the network.
 */
function networkSwitchBox(object $test, bool $applies = true, bool $removes = true, ?array $labels = null): void
{
    $containers = Mockery::mock(ContainerSupervisor::class)->makePartial();
    $apply = $containers->shouldReceive('apply');
    $applies ? $apply->andReturnNull() : $apply->andThrow(new ProvisioningFailedException('container_start', 'up-reference'));
    app()->instance(ContainerSupervisor::class, $containers);

    $docker = Mockery::mock(DockerResources::class);
    $docker->shouldReceive('networks')->andReturnUsing(function () use ($test) {
        $test->ran[] = 'networks';

        return [
            ['name' => $test->default, 'built_in' => false, 'containers' => [], 'sites' => []],
            ['name' => 'team-net', 'built_in' => false, 'containers' => [], 'sites' => [['id' => $test->site->id, 'name' => 'mover']]],
        ];
    });
    $docker->shouldReceive('composeNetwork')->andReturn($labels ?? ['project' => "sv-app-{$test->site->id}", 'network' => 'default']);
    $docker->shouldReceive('removeNetwork')->andReturnUsing(function (string $name) use ($test, $removes) {
        $test->ran[] = "rm:{$name}";

        return new ServerOpsResult(ok: $removes, reference: 'rm-reference', answered: true);
    });
    app()->instance(DockerResources::class, $docker);
}

it('removes the network the site was created on once it is running on the new one', function () {
    networkSwitchBox($this);

    app(UpdateContainerSettings::class)->execute($this->site, ['docker_network' => 'team-net']);

    expect($this->ran)->toContain("rm:{$this->default}");
});

it('leaves it alone when the move did not come up', function () {
    // Until the apply succeeds the container may still be on the old network.
    networkSwitchBox($this, applies: false);

    expect(fn () => app(UpdateContainerSettings::class)->execute($this->site, ['docker_network' => 'team-net']))
        ->toThrow(ProvisioningFailedException::class);

    expect($this->ran)->toBe([]);
});

it('does not even look when the network did not change', function () {
    networkSwitchBox($this);

    app(UpdateContainerSettings::class)->execute($this->site, ['memory_limit' => '768m']);

    expect($this->ran)->toBe([]);
});

it('keeps a network Compose did not create for this site', function () {
    networkSwitchBox($this, labels: ['project' => 'somebody-else', 'network' => 'default']);

    app(UpdateContainerSettings::class)->execute($this->site, ['docker_network' => 'team-net']);

    expect($this->ran)->toBe(['networks']);
});

it('still saves when Docker refuses to remove it', function () {
    // The move the user asked for has happened; a leftover network is tidiness.
    networkSwitchBox($this, removes: false);

    $saved = app(UpdateContainerSettings::class)->execute($this->site, ['docker_network' => 'team-net']);

    expect($saved->docker_network)->toBe('team-net')
        ->and($this->ran)->toContain("rm:{$this->default}");
});

it('never removes the default network when the site is moved back onto it', function () {
    // Back on its own default, that network is the one it runs on now. Docker
    // would refuse with an endpoint attached; the panel must not ask.
    $this->site->forceFill(['docker_network' => 'team-net'])->save();
    networkSwitchBox($this);

    app(UpdateContainerSettings::class)->execute($this->site, ['docker_network' => null]);

    expect($this->ran)->not->toContain("rm:{$this->default}");
});
