<?php

use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\Steps\ArchiveVolumes;
use App\Services\Server\Docker\VolumeSizes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Real tar on real directories, with sudo off — the same approach the excludes
 * tests take, and for the same reason: what tar does with a path is the thing
 * under test, and no fake can show it. Only volume *discovery* is stubbed,
 * because that part needs a Docker daemon; `VolumeSizesDiscoverTest` pins the
 * shape this stub stands in for, so the stub cannot drift from the real one
 * without something failing.
 */
beforeEach(function () {
    config(['server.privilege.sudo' => false]);

    $this->tmp = sys_get_temp_dir().'/sv-archive-volumes-'.Str::random(8);

    // Two volumes with distinguishable contents, including a dotfile — an
    // application's "already initialised" marker is usually a dotfile, and
    // losing it means first-run setup happens again on restore.
    File::makeDirectory("{$this->tmp}/mounts/data/_data/nested", 0755, true);
    File::put("{$this->tmp}/mounts/data/_data/content.txt", 'the real data');
    File::put("{$this->tmp}/mounts/data/_data/.initialised", 'yes');
    File::put("{$this->tmp}/mounts/data/_data/nested/deep.txt", 'deeper');

    File::makeDirectory("{$this->tmp}/mounts/cache/_data", 0755, true);
    File::put("{$this->tmp}/mounts/cache/_data/throwaway.bin", 'cache');

    $home = "{$this->tmp}/home/owner";
    File::makeDirectory("{$home}/ghost", 0755, true);
    File::put("{$home}/ghost/compose.yml", "services:\n  app:\n    image: ghost:5\n");

    $user = SystemUser::create(['username' => 'owner', 'home_path' => $home]);

    $this->application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Ghost', 'slug' => 'ghost',
        'domain' => 'ghost.test', 'site_type' => 'ghost', 'serving_profile' => 'docker',
        'status' => 'active', 'web_root' => '/',
    ]);

    $destination = StorageDestination::create([
        'name' => 'dest-'.Str::random(6), 'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
    ]);

    $this->target = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $destination->id,
        'type' => 'volumes_config', 'retention_count' => 2, 'frequency' => 'manual', 'enabled' => true,
    ]);

    $this->working = "{$this->tmp}/run";
    File::makeDirectory($this->working, 0750, true);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/** Stands in for the one call that needs a Docker daemon. */
function stubDiscovery(array $volumes): void
{
    $stub = Mockery::mock(VolumeSizes::class);
    $stub->shouldReceive('discover')->andReturn($volumes);
    app()->instance(VolumeSizes::class, $stub);
}

/**
 * Run the step and hand back the context it filled in.
 *
 * Takes its state as arguments rather than reaching through `test()`: a helper
 * that reads the test case's properties is a helper that only works from one
 * place, and the failure when it does not is an undefined-property error
 * pointing at the test class rather than at the mistake.
 */
function runArchiveVolumes(BackupTarget $target, string $working): BackupContext
{
    $backup = Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $target->application_id,
        'type' => $target->type->value,
        'status' => BackupStatus::Running->value,
    ]);

    $context = new BackupContext($backup, $target->fresh(), $working);

    app(ArchiveVolumes::class)->run($context);

    return $context;
}

/** The two volumes created in `beforeEach`, as discovery would report them. */
function volumeMounts(string $tmp): array
{
    return [
        'sv-app-1_data' => $tmp.'/mounts/data/_data',
        'sv-app-1_cache' => $tmp.'/mounts/cache/_data',
    ];
}

/** A context for a target without running the step — for `appliesTo` checks. */
function volumeContextFor(BackupTarget $target, string $working): BackupContext
{
    $backup = Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $target->application_id,
        'type' => $target->type->value,
        'status' => BackupStatus::Running->value,
    ]);

    return new BackupContext($backup, $target->fresh(), $working);
}

it('writes one tar per volume, holding the volume contents at the archive root', function () {
    stubDiscovery(volumeMounts($this->tmp));

    $context = runArchiveVolumes($this->target, $this->working);

    $tar = $this->working.'/volumes/sv-app-1_data.tar';

    expect(is_file($tar))->toBeTrue();

    $listing = shell_exec('tar -tf '.escapeshellarg($tar));

    // Relative to the mountpoint, not `/var/lib/docker/volumes/...`. An archive
    // of absolute paths can only ever be poured back where it came from, and a
    // restore runs against a volume whose host path is a different string.
    expect($listing)->toContain('./content.txt')
        ->and($listing)->toContain('./nested/deep.txt')
        // The dotfile, which `find -delete`-then-extract on restore depends on.
        ->and($listing)->toContain('./.initialised')
        ->and($listing)->not->toContain('_data/content.txt');
});

it('records every volume in the manifest, with its size and how it was captured', function () {
    stubDiscovery(volumeMounts($this->tmp));

    $context = runArchiveVolumes($this->target, $this->working);

    $volumes = collect($context->manifest['volumes'])->keyBy('name');

    expect($volumes)->toHaveCount(2)
        ->and($volumes['sv-app-1_data']['file'])->toBe('volumes/sv-app-1_data.tar')
        ->and($volumes['sv-app-1_data']['bytes'])->toBeGreaterThan(0)
        ->and($volumes['sv-app-1_data']['strategy'])->toBe('file_copy')
        // The honest part. A live file copy is crash-consistent at best, and
        // the restore screen reads this to say so.
        ->and($volumes['sv-app-1_data']['consistent'])->toBeFalse();
});

it('captures every volume when no selection is stored, including one added later', function () {
    // Null scope is the default and means "everything", so that a volume a site
    // gains next month is still covered. A list fixed at setup would silently
    // stop covering it.
    expect($this->target->volume_scope)->toBeNull();

    stubDiscovery(volumeMounts($this->tmp) + ['sv-app-1_added_later' => $this->tmp.'/mounts/cache/_data']);

    $context = runArchiveVolumes($this->target, $this->working);

    expect(collect($context->manifest['volumes'])->pluck('name')->all())
        ->toContain('sv-app-1_added_later')
        ->and($context->manifest['volumes_skipped'])->toBe([]);
});

it('captures only the selected volumes, and records the ones it left out', function () {
    $this->target->update(['volume_scope' => ['sv-app-1_data']]);

    stubDiscovery(volumeMounts($this->tmp));

    $context = runArchiveVolumes($this->target, $this->working);

    // An archive missing data has to say so where a restore will read it,
    // or a restore quietly produces a half-site.
    expect(collect($context->manifest['volumes'])->pluck('name')->all())->toBe(['sv-app-1_data'])
        ->and($context->manifest['volumes_skipped'])->toBe(['sv-app-1_cache'])
        ->and(is_file($this->working.'/volumes/sv-app-1_cache.tar'))->toBeFalse();
});

it('ignores a selected volume that no longer exists rather than failing the run', function () {
    $this->target->update(['volume_scope' => ['sv-app-1_data', 'sv-app-1_deleted']]);

    stubDiscovery(volumeMounts($this->tmp));

    $context = runArchiveVolumes($this->target, $this->working);

    // Somebody deleting one volume is not a reason to stop backing up the rest.
    expect(collect($context->manifest['volumes'])->pluck('name')->all())->toBe(['sv-app-1_data']);
});

it('records an empty volume list rather than nothing at all', function () {
    stubDiscovery([]);

    $context = runArchiveVolumes($this->target, $this->working);

    // A container site with no volumes keeps nothing across a rebuild, which is
    // a real answer. The manifest saying so is what separates it from a backup
    // that silently found nothing.
    expect($context->manifest)->toHaveKey('volumes')
        ->and($context->manifest['volumes'])->toBe([])
        ->and(is_dir($this->working.'/volumes'))->toBeFalse();
});

it('does not apply to a target that only wants the config', function () {
    $this->target->update(['type' => 'config']);

    $context = volumeContextFor($this->target->fresh(), $this->working);

    expect(app(ArchiveVolumes::class)->appliesTo($context))->toBeFalse();
});

it('applies to both container types that include volumes', function (string $type, bool $expected) {
    $this->target->update(['type' => $type]);

    $context = volumeContextFor($this->target->fresh(), $this->working);

    expect(app(ArchiveVolumes::class)->appliesTo($context))->toBe($expected);
})->with([
    ['volumes', true],
    ['volumes_config', true],
    ['config', false],
    ['full', false],
]);
