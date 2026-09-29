<?php

use App\Actions\Server\Application\DeleteApplicationDockerResources;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\ServerOpsResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Deleting a site's Docker network and volumes, when asked to.
 *
 * Opt-in, like files and databases: deleting a panel record must not silently
 * destroy data, and a volume holding a Ghost site's MySQL is exactly as
 * unrecoverable as a database.
 *
 * The value of these tests is in the REFUSALS. A network shared by two sites is
 * the whole point of the networks feature, so deleting the site you asked about
 * must never break the one you did not.
 */
beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);
});

function dockerSite(string $name, array $overrides = []): Application
{
    $user = SystemUser::create(['username' => $name, 'home_path' => "/home/{$name}"]);

    return Application::forceCreate(array_merge([
        'system_user_id' => $user->id,
        'name' => $name,
        'slug' => $name,
        'domain' => "{$name}.test",
        'web_root' => 'public_html',
        'site_type' => 'docker',
        'serving_profile' => 'docker',
        'status' => 'active',
        'app_port' => 20200 + strlen($name),
    ], $overrides));
}

/** @param  list<array<string,mixed>>  $volumes */
function fakeDockerState(array $volumes, array $networks = [], array &$ran = []): void
{
    $mock = Mockery::mock(DockerResources::class);
    $mock->shouldReceive('volumes')->andReturn($volumes);
    $mock->shouldReceive('networks')->andReturn($networks);
    $mock->shouldReceive('removeVolume')->andReturnUsing(function (string $name) use (&$ran) {
        $ran[] = "volume:{$name}";

        return new ServerOpsResult(ok: true, reference: 'r', answered: true);
    });
    $mock->shouldReceive('removeNetwork')->andReturnUsing(function (string $name) use (&$ran) {
        $ran[] = "network:{$name}";

        return new ServerOpsResult(ok: true, reference: 'r', answered: true);
    });

    app()->instance(DockerResources::class, $mock);
}

it('removes a volume only this site mounts', function () {
    $site = dockerSite('alpha', ['volume_mounts' => [['volume' => 'alpha_db', 'path' => '/var/lib/mysql']]]);

    $ran = [];
    fakeDockerState([[
        'name' => 'alpha_db', 'in_use' => false, 'containers' => 0, 'container_names' => [],
        'sites' => [['id' => $site->id, 'name' => 'alpha', 'path' => '/var/lib/mysql']],
    ]], [], $ran);

    $outcome = app(DeleteApplicationDockerResources::class)->execute($site);

    expect($ran)->toBe(['volume:alpha_db'])
        ->and($outcome['removed'])->toBe(['volume:alpha_db']);
});

it('keeps a volume another site also mounts', function () {
    // `sites` includes the site being deleted, so it is excluded by id — counting
    // instead would keep every volume of every site.
    $site = dockerSite('alpha', ['volume_mounts' => [['volume' => 'shared_db', 'path' => '/data']]]);
    $other = dockerSite('beta', ['volume_mounts' => [['volume' => 'shared_db', 'path' => '/data']]]);

    $ran = [];
    fakeDockerState([[
        'name' => 'shared_db', 'in_use' => false, 'containers' => 0, 'container_names' => [],
        'sites' => [
            ['id' => $site->id, 'name' => 'alpha', 'path' => '/data'],
            ['id' => $other->id, 'name' => 'beta', 'path' => '/data'],
        ],
    ]], [], $ran);

    $outcome = app(DeleteApplicationDockerResources::class)->execute($site);

    expect($ran)->toBe([])
        ->and($outcome['kept'])->toBe(['shared_db']);
});

it('keeps a volume a container is still holding', function () {
    // The same guard the Docker page enforces. A running container means data is
    // being written to it right now.
    $site = dockerSite('alpha', ['volume_mounts' => [['volume' => 'busy_db', 'path' => '/data']]]);

    $ran = [];
    fakeDockerState([[
        'name' => 'busy_db', 'in_use' => true, 'containers' => 1, 'container_names' => ['x'],
        'sites' => [['id' => $site->id, 'name' => 'alpha', 'path' => '/data']],
    ]], [], $ran);

    expect(app(DeleteApplicationDockerResources::class)->execute($site)['kept'])->toBe(['busy_db']);
    expect($ran)->toBe([]);
});

it('keeps a network another site is on', function () {
    // Two sites on one network is the entire point of the networks feature.
    // Deleting the site you asked about must not break the one you did not.
    $site = dockerSite('alpha', ['docker_network' => 'shared-net']);
    $other = dockerSite('beta', ['docker_network' => 'shared-net']);

    $ran = [];
    fakeDockerState([], [[
        'name' => 'shared-net', 'built_in' => false, 'containers' => [],
        'sites' => [
            ['id' => $site->id, 'name' => 'alpha'],
            ['id' => $other->id, 'name' => 'beta'],
        ],
    ]], $ran);

    expect(app(DeleteApplicationDockerResources::class)->execute($site)['kept'])->toBe(['shared-net']);
    expect($ran)->toBe([]);
});

it('removes a network no other site is on', function () {
    $site = dockerSite('alpha', ['docker_network' => 'alpha-net']);

    $ran = [];
    fakeDockerState([], [[
        'name' => 'alpha-net', 'built_in' => false, 'containers' => [],
        'sites' => [['id' => $site->id, 'name' => 'alpha']],
    ]], $ran);

    expect(app(DeleteApplicationDockerResources::class)->execute($site)['removed'])
        ->toBe(['network:alpha-net']);
});

it('never removes one of Docker\'s own networks', function () {
    // A site could have been pointed at `bridge`. Docker recreates these on
    // restart, so a delete is a control that either fails or does damage.
    $site = dockerSite('alpha', ['docker_network' => 'bridge']);

    $ran = [];
    fakeDockerState([], [[
        'name' => 'bridge', 'built_in' => true, 'containers' => [],
        'sites' => [['id' => $site->id, 'name' => 'alpha']],
    ]], $ran);

    app(DeleteApplicationDockerResources::class)->execute($site);

    expect($ran)->toBe([]);
});

it('says nothing and breaks nothing when the objects are already gone', function () {
    $site = dockerSite('alpha', [
        'docker_network' => 'missing-net',
        'volume_mounts' => [['volume' => 'missing_vol', 'path' => '/data']],
    ]);

    $ran = [];
    fakeDockerState([], [], $ran);

    $outcome = app(DeleteApplicationDockerResources::class)->execute($site);

    expect($ran)->toBe([])
        ->and($outcome['removed'])->toBe([])
        ->and($outcome['kept'])->toBe([]);
});

it('records what it kept as well as what it removed', function () {
    // "We did not delete this" is the half somebody comes looking for when they
    // find a volume still on the server.
    $site = dockerSite('alpha', ['volume_mounts' => [['volume' => 'busy_db', 'path' => '/data']]]);

    $ran = [];
    fakeDockerState([[
        'name' => 'busy_db', 'in_use' => true, 'containers' => 1, 'container_names' => ['x'],
        'sites' => [['id' => $site->id, 'name' => 'alpha', 'path' => '/data']],
    ]], [], $ran);

    app(DeleteApplicationDockerResources::class)->execute($site);

    $log = ActivityLog::where('action', 'docker_resources_removed')->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['kept'])->toBe(['busy_db']);
});
