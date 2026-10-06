<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Docker\VolumeSizes;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Pins the shape `ArchiveVolumesTest` stubs.
 *
 * That test replaces `discover()` with a canned `name => mountpoint` map,
 * because walking real volumes needs a Docker daemon. A stub is only as good as
 * the agreement it stands in for: if `discover()` ever returns a bare list again
 * — which is what it returned before the backup work needed names — the stub
 * keeps passing and the step breaks only on a real server. So the contract is
 * asserted here, against a faked `docker volume ls`, and the two tests fail
 * together or not at all.
 */
function volumeListOps(string $stdout, bool $answered = true): ServerOps
{
    $process = Mockery::mock(ProcessResult::class);
    $process->shouldReceive('output')->andReturn($stdout);
    $process->shouldReceive('errorOutput')->andReturn('');

    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('run')->andReturn(new ServerOpsResult(
        ok: true, reference: 'r', result: $process, answered: $answered,
    ));

    return $ops;
}

function discoverFor(ServerOps $ops, Application $application): array
{
    return (new VolumeSizes($ops))->discover($application);
}

function volumeApplication(array $mounts = []): Application
{
    $user = SystemUser::create(['username' => 'vol', 'home_path' => '/home/vol']);

    return Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Ghost', 'slug' => 'ghost',
        'domain' => 'ghost.test', 'site_type' => 'ghost', 'serving_profile' => 'docker',
        'status' => 'active', 'web_root' => '/', 'volume_mounts' => $mounts,
    ]);
}

it('returns volume name => mountpoint, which is the shape a backup needs', function () {
    $application = volumeApplication();

    $ops = volumeListOps(implode("\n", [
        'sv-app-'.$application->id.'_data'."\t".'/var/lib/docker/volumes/sv-app-'.$application->id.'_data/_data',
        'sv-app-'.$application->id.'_cache'."\t".'/var/lib/docker/volumes/sv-app-'.$application->id.'_cache/_data',
        'someone-elses'."\t".'/var/lib/docker/volumes/someone-elses/_data',
    ]));

    $discovered = discoverFor($ops, $application);

    // Keys are names. The name is what a restore recreates; the mountpoint is
    // only what the backup reads.
    expect(array_keys($discovered))->toBe([
        'sv-app-'.$application->id.'_data',
        'sv-app-'.$application->id.'_cache',
    ])->and($discovered['sv-app-'.$application->id.'_data'])
        ->toBe('/var/lib/docker/volumes/sv-app-'.$application->id.'_data/_data');
});

it('includes a volume attached by hand that the panel never prefixed', function () {
    // The two sources are each incomplete: `volume_mounts` holds what somebody
    // attached on the Container screen, and the prefix covers what the panel
    // created. A one-click app's volumes appear only in the second.
    $application = volumeApplication([['volume' => 'brought-my-own', 'path' => '/data']]);

    $ops = volumeListOps(implode("\n", [
        'brought-my-own'."\t".'/var/lib/docker/volumes/brought-my-own/_data',
        'sv-app-'.$application->id.'_data'."\t".'/var/lib/docker/volumes/sv-app-'.$application->id.'_data/_data',
        'unrelated'."\t".'/var/lib/docker/volumes/unrelated/_data',
    ]));

    expect(array_keys(discoverFor($ops, $application)))
        ->toContain('brought-my-own')
        ->toContain('sv-app-'.$application->id.'_data')
        ->not->toContain('unrelated');
});

it('never counts another site\'s volumes as this one\'s', function () {
    // The prefix match is `str_starts_with`, and a false positive is the real
    // danger here: `sv-app-2_` must not sweep in site 20's volumes, because a
    // backup that silently includes someone else's data is worse than one that
    // misses some.
    $user = SystemUser::create(['username' => 'two', 'home_path' => '/home/two']);
    $site2 = Application::forceCreate([
        'id' => 2, 'system_user_id' => $user->id, 'name' => 'Two', 'slug' => 'two',
        'domain' => 'two.test', 'site_type' => 'ghost', 'serving_profile' => 'docker',
        'status' => 'active', 'web_root' => '/',
    ]);

    $ops = volumeListOps(implode("\n", [
        'sv-app-2_data'."\t".'/var/lib/docker/volumes/sv-app-2_data/_data',
        'sv-app-20_data'."\t".'/var/lib/docker/volumes/sv-app-20_data/_data',
    ]));

    expect(array_keys(discoverFor($ops, $site2)))->toBe(['sv-app-2_data']);
});

it('answers with nothing when docker could not be asked', function () {
    // Not an exception: the size job this also feeds runs unattended, and a
    // daemon that did not answer is not the same as a site with no volumes —
    // but it is not a reason to fail either. `ArchiveVolumes` records an empty
    // list, which is visible in the manifest.
    $application = volumeApplication();

    expect(discoverFor(volumeListOps('', answered: false), $application))->toBe([]);
});
