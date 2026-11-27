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

/**
 * The action is two phases on purpose — decide while the row exists, remove once the
 * containers are gone. Tests drive both, because either alone proves nothing: the
 * plan without the apply removes nothing, and the apply without the plan has no
 * safety checks.
 *
 * @return array{removed: list<string>, kept: list<string>}
 */
function deleteDockerResources(Application $site): array
{
    $action = app(DeleteApplicationDockerResources::class);

    return $action->apply($site, $action->plan($site));
}

/*
 * Deleting a site's Docker network and volumes, when asked to. Opt-in, like files and
 * databases: a volume holding a site's MySQL is as unrecoverable as a database.
 *
 * The value is in the REFUSALS: deleting one site must never break another sharing a network.
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

/**
 * @param  list<array<string,mixed>>  $volumes
 * @param  array<string, array{project: string, network: string}|null>  $labels  what Compose's
 *                                                                               labels say per network; absent = none.
 */
function fakeDockerState(array $volumes, array $networks = [], array &$ran = [], array $labels = []): void
{
    $mock = Mockery::mock(DockerResources::class);
    $mock->shouldReceive('volumes')->andReturn($volumes);
    $mock->shouldReceive('networks')->andReturn($networks);
    $mock->shouldReceive('composeNetwork')->andReturnUsing(function (string $name) use (&$ran, $labels) {
        $ran[] = "labels:{$name}";

        return $labels[$name] ?? null;
    });
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

    $outcome = deleteDockerResources($site);

    expect($ran)->toBe(['volume:alpha_db'])
        ->and($outcome['removed'])->toBe(['volume:alpha_db']);
});

it('keeps a volume another site also mounts', function () {
    // `sites` includes the site being deleted, so exclude it by id; counting would keep every volume.
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

    $outcome = deleteDockerResources($site);

    expect($ran)->toBe([])
        ->and($outcome['kept'])->toBe(['shared_db:mounted-by-beta']);
});

it('keeps a volume a FOREIGN container is still holding', function () {
    // Something outside this site holds it: a container started by hand, or another project's.
    $site = dockerSite('alpha', ['volume_mounts' => [['volume' => 'busy_db', 'path' => '/data']]]);

    $ran = [];
    fakeDockerState([[
        'name' => 'busy_db', 'in_use' => true, 'containers' => 1,
        'container_names' => ['someone-elses-container'],
        'sites' => [['id' => $site->id, 'name' => 'alpha', 'path' => '/data']],
    ]], [], $ran);

    expect(deleteDockerResources($site)['kept'])
        ->toBe(['busy_db:held-by-someone-elses-container']);
    expect($ran)->toBe([]);
});

it('keeps a network another site is on', function () {
    // Sites sharing a network is the point of the networks feature.
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

    expect(deleteDockerResources($site)['kept'])->toBe(['shared-net:joined-by-beta']);
    expect($ran)->toBe([]);
});

it('removes a network no other site is on', function () {
    $site = dockerSite('alpha', ['docker_network' => 'alpha-net']);

    $ran = [];
    fakeDockerState([], [[
        'name' => 'alpha-net', 'built_in' => false, 'containers' => [],
        'sites' => [['id' => $site->id, 'name' => 'alpha']],
    ]], $ran);

    expect(deleteDockerResources($site)['removed'])
        ->toBe(['network:alpha-net']);
});

it('never removes one of Docker\'s own networks', function () {
    // A site could have been pointed at `bridge`; Docker recreates its own networks on restart.
    $site = dockerSite('alpha', ['docker_network' => 'bridge']);

    $ran = [];
    fakeDockerState([], [[
        'name' => 'bridge', 'built_in' => true, 'containers' => [],
        'sites' => [['id' => $site->id, 'name' => 'alpha']],
    ]], $ran);

    deleteDockerResources($site);

    expect($ran)->toBe([]);
});

it('says nothing and breaks nothing when the objects are already gone', function () {
    $site = dockerSite('alpha', [
        'docker_network' => 'missing-net',
        'volume_mounts' => [['volume' => 'missing_vol', 'path' => '/data']],
    ]);

    // A listing that ANSWERED and does not contain them; an empty listing is indistinguishable,
    // hence the separate `not-listed` reason and the test below.
    $ran = [];
    fakeDockerState([[
        'name' => 'somebody-elses', 'in_use' => false, 'containers' => 0,
        'container_names' => [], 'sites' => [],
    ]], [], $ran);

    $outcome = deleteDockerResources($site);

    // Recorded as already-gone, not skipped in silence.
    expect($ran)->toBe([])
        ->and($outcome['removed'])->toBe([])
        ->and($outcome['kept'])->toBe(['missing_vol:already-gone', 'missing-net:not-listed']);
});

it('records what it kept as well as what it removed', function () {
    // "We did not delete this" is what someone looks for when a volume is still on the server.
    $site = dockerSite('alpha', ['volume_mounts' => [['volume' => 'busy_db', 'path' => '/data']]]);

    $ran = [];
    fakeDockerState([[
        'name' => 'busy_db', 'in_use' => true, 'containers' => 1, 'container_names' => ['x'],
        'sites' => [['id' => $site->id, 'name' => 'alpha', 'path' => '/data']],
    ]], [], $ran);

    deleteDockerResources($site);

    $log = ActivityLog::where('action', 'docker_resources_removed')->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['kept'])->toBe(['busy_db:held-by-x']);
});

it('removes a volume its OWN container is still holding', function () {
    // The action runs before `compose down`, so the site's own container is still attached to
    // its own volume; guarding on `in_use` would make the flag a no-op for every running site.
    $site = dockerSite('alpha', ['volume_mounts' => [['volume' => 'alpha_db', 'path' => '/data']]]);

    $ran = [];
    fakeDockerState([[
        'name' => 'alpha_db', 'in_use' => true, 'containers' => 1,
        // Compose names it `<project>-<service>-1`, and the project is `sv-app-<id>`.
        'container_names' => ["sv-app-{$site->id}-app-1"],
        'sites' => [['id' => $site->id, 'name' => 'alpha', 'path' => '/data']],
    ]], [], $ran);

    expect(deleteDockerResources($site)['removed'])
        ->toBe(['volume:alpha_db']);
});

it('removes a network only its OWN containers are attached to', function () {
    // As with the volume: the site has not been stopped yet.
    $site = dockerSite('alpha', ['docker_network' => 'alpha-net']);

    $ran = [];
    fakeDockerState([], [[
        'name' => 'alpha-net', 'built_in' => false,
        'containers' => [['name' => "sv-app-{$site->id}-app-1", 'ports' => [], 'published' => false]],
        'sites' => [['id' => $site->id, 'name' => 'alpha']],
    ]], $ran);

    expect(deleteDockerResources($site)['removed'])
        ->toBe(['network:alpha-net']);
});

it('keeps a network a foreign container is attached to', function () {
    // Removing it would detach a container someone connected by hand.
    $site = dockerSite('alpha', ['docker_network' => 'alpha-net']);

    $ran = [];
    fakeDockerState([], [[
        'name' => 'alpha-net', 'built_in' => false,
        'containers' => [['name' => 'stranger', 'ports' => [], 'published' => false]],
        'sites' => [['id' => $site->id, 'name' => 'alpha']],
    ]], $ran);

    expect(deleteDockerResources($site)['kept'])->toBe(['alpha-net:attached-stranger']);
    expect($ran)->toBe([]);
});

it('says so when Docker could not be asked at all', function () {
    // `volumes()` returns an empty array when `docker system df -v` does not answer; reading
    // that as "no volumes exist" reports success having removed nothing.
    $site = dockerSite('alpha', ['volume_mounts' => [['volume' => 'alpha_db', 'path' => '/data']]]);

    $ran = [];
    fakeDockerState([], [], $ran);

    $kept = deleteDockerResources($site)['kept'];

    expect($kept)->toContain('unreadable:docker-volume-list')
        ->and($kept)->toContain('alpha_db:not-listed')
        ->and($ran)->toBe([]);
});

describe('the network the site was created on, after it moved', function () {
    // A site moved off its own network leaves `sv-app-<id>_default` behind on delete.

    function movedSite(): Application
    {
        return dockerSite('alpha', ['docker_network' => 'alpha-net']);
    }

    /** @return array<string, mixed> */
    function defaultNetworkRow(Application $site, array $sites = [], array $containers = []): array
    {
        return ['name' => "sv-app-{$site->id}_default", 'built_in' => false, 'containers' => $containers, 'sites' => $sites];
    }

    function ownLabels(Application $site): array
    {
        return ["sv-app-{$site->id}_default" => ['project' => "sv-app-{$site->id}", 'network' => 'default']];
    }

    it('removes it along with the network the site is on now', function () {
        $site = movedSite();

        $ran = [];
        fakeDockerState([], [
            ['name' => 'alpha-net', 'built_in' => false, 'containers' => [], 'sites' => [['id' => $site->id, 'name' => 'alpha']]],
            defaultNetworkRow($site),
        ], $ran, ownLabels($site));

        expect(deleteDockerResources($site)['removed'])
            ->toBe(['network:alpha-net', "network:sv-app-{$site->id}_default"]);
    });

    it('keeps one with the right name that Compose did not create for this site', function () {
        // Anybody can `docker network create sv-app-6_default`: the name is a guess, the labels
        // are the fact.
        $site = movedSite();

        $ran = [];
        fakeDockerState([], [defaultNetworkRow($site)], $ran);

        expect(deleteDockerResources($site)['kept'])->toContain("sv-app-{$site->id}_default:not-created-by-this-site")
            ->and($ran)->not->toContain("network:sv-app-{$site->id}_default");
    });

    it('keeps one Compose made for a different project', function () {
        $site = movedSite();

        $ran = [];
        fakeDockerState([], [defaultNetworkRow($site)], $ran, [
            "sv-app-{$site->id}_default" => ['project' => 'someone-else', 'network' => 'default'],
        ]);

        expect(deleteDockerResources($site)['kept'])->toContain("sv-app-{$site->id}_default:not-created-by-this-site")
            ->and($ran)->not->toContain("network:sv-app-{$site->id}_default");
    });

    it('keeps it when another site was put on it', function () {
        $site = movedSite();
        $other = dockerSite('beta', ['docker_network' => "sv-app-{$site->id}_default"]);

        $ran = [];
        fakeDockerState([], [defaultNetworkRow($site, sites: [['id' => $other->id, 'name' => 'beta']])], $ran, ownLabels($site));

        expect(deleteDockerResources($site)['kept'])->toContain("sv-app-{$site->id}_default:joined-by-beta")
            ->and($ran)->not->toContain("network:sv-app-{$site->id}_default");
    });

    it('keeps it when a container outside this site is attached', function () {
        $site = movedSite();

        $ran = [];
        fakeDockerState([], [defaultNetworkRow($site, containers: [['name' => 'debug-shell', 'ports' => []]])], $ran, ownLabels($site));

        expect(deleteDockerResources($site)['kept'])->toContain("sv-app-{$site->id}_default:attached-debug-shell")
            ->and($ran)->not->toContain("network:sv-app-{$site->id}_default");
    });

    it('does not touch a site\'s own default network when it never moved', function () {
        // `compose down` removes that one itself; planning it too would add a "remove failed".
        $site = dockerSite('alpha');

        $ran = [];
        fakeDockerState([], [defaultNetworkRow($site)], $ran, ownLabels($site));

        deleteDockerResources($site);

        expect($ran)->toBe([]);
    });
});
