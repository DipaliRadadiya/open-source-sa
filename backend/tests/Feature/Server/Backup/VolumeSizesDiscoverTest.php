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
 * Pins the shape `ArchiveVolumesTest` stubs: `ArchiveVolumesTest` replaces `discover()` with a
 * canned `name => mountpoint` map (real volumes need a Docker daemon), so the contract is
 * asserted here against a faked `docker volume ls`; otherwise a stub could keep passing while
 * the real step breaks.
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

    // Keys are names: a restore recreates by name; the mountpoint is only what the backup reads.
    expect(array_keys($discovered))->toBe([
        'sv-app-'.$application->id.'_data',
        'sv-app-'.$application->id.'_cache',
    ])->and($discovered['sv-app-'.$application->id.'_data'])
        ->toBe('/var/lib/docker/volumes/sv-app-'.$application->id.'_data/_data');
});

it('includes a volume attached by hand that the panel never prefixed', function () {
    // Two incomplete sources: `volume_mounts` holds hand-attached volumes, the prefix covers
    // the ones the panel created (a one-click app's appear only there).
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
    // A prefix false positive is the danger: `sv-app-2_` must not sweep in site 20's volumes,
    // since a backup holding someone else's data is worse than one that misses some.
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
    // Not an exception: the size job runs unattended. `ArchiveVolumes` records an empty list,
    // visible in the manifest.
    $application = volumeApplication();

    expect(discoverFor(volumeListOps('', answered: false), $application))->toBe([]);
});

it('tells "has none" apart from "could not ask" for the refusals that act on it (S-02)', function () {
    // A `volumes` backup is refused only on `[]`; an unanswered daemon must not refuse a site
    // that has volumes.
    $application = volumeApplication();
    $sizes = fn (ServerOps $ops): VolumeSizes => new VolumeSizes($ops);

    expect($sizes(volumeListOps('', answered: false))->find($application))->toBeNull()
        ->and($sizes(volumeListOps('', answered: false))->hasNone($application))->toBeFalse()
        ->and($sizes(volumeListOps('someone-elses'."\t".'/var/lib/docker/volumes/someone-elses/_data'))->find($application))->toBe([])
        ->and($sizes(volumeListOps(''))->hasNone($application))->toBeTrue()
        ->and($sizes(volumeListOps('sv-app-'.$application->id.'_data'."\t".'/var/lib/docker/volumes/x/_data'))->hasNone($application))->toBeFalse();
});
