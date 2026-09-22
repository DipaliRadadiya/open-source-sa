<?php

use App\Contracts\BackupStep;
use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\Steps\PruneOldBackups;
use App\Services\Server\Backups\Storage\DestinationDisk;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * A safety backup has to be recognisable as one from the instant it exists.
 *
 * `SafetyBackup` used to call `BackupRunner::run()` and set `is_safety = true`
 * on the row it got back. Everything in between — a multi-gigabyte archive and
 * an upload that can run for an hour — was a window where the flag was false.
 *
 * `PruneOldBackups` protects these rows with `where('is_safety', false)`,
 * precisely so retention cannot "remove the parachute at exactly the wrong
 * moment". A row that dies inside the window loses that protection, and a later
 * retention pass deletes it *and* its archive in the bucket.
 *
 * Not hypothetical: on 2026-09-22 an OOM killed the worker mid-run and left
 * restore 5's safety backup reading `is_safety = 0`, with the restore's
 * `safety_backup_id` still null. Both are still in that database.
 */

function safetyTarget(): BackupTarget
{
    $application = Application::factory()->create();

    $destination = StorageDestination::create([
        'name' => 'gDrive',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
    ]);

    return BackupTarget::create([
        'application_id' => $application->id,
        'storage_destination_id' => $destination->id,
        'type' => 'full',
        'retention_count' => 2,
        'frequency' => 'manual',
    ]);
}

/**
 * A step that reads the row back from the database while the run is still in
 * flight, which is the only way to prove the flag is there *during* the work
 * rather than bolted on at the end.
 */
function flagSpy(?bool &$seen): BackupStep
{
    return new class($seen) implements BackupStep
    {
        public function __construct(private ?bool &$seen) {}

        public function key(): string
        {
            return 'spy';
        }

        public function appliesTo(BackupContext $context): bool
        {
            return true;
        }

        public function run(BackupContext $context): void
        {
            // Read back, not taken from the in-memory model: a flag that only
            // exists on the object is no protection at all, because the prune
            // query runs against the table.
            $this->seen = (bool) Backup::query()
                ->whereKey($context->backup->getKey())
                ->value('is_safety');
        }

        public function cleanup(BackupContext $context): void {}
    };
}

it('flags a safety backup at creation, not after the upload', function () {
    $seen = null;
    $spy = flagSpy($seen);

    app()->bind(get_class($spy), fn () => $spy);
    config()->set('server.backups.steps', [get_class($spy)]);

    (new BackupRunner)->run(safetyTarget(), null, isSafety: true);

    // The window: if this is false, a worker dying mid-upload leaves an
    // unprotected safety backup.
    expect($seen)->toBeTrue();
});

it('leaves an ordinary backup unflagged', function () {
    $seen = null;
    $spy = flagSpy($seen);

    app()->bind(get_class($spy), fn () => $spy);
    config()->set('server.backups.steps', [get_class($spy)]);

    (new BackupRunner)->run(safetyTarget());

    expect($seen)->toBeFalse();
});

it('keeps retention from deleting a safety backup', function () {
    $target = safetyTarget();

    // Retention is 2, and these three are all older than the run doing the
    // pruning — so without the exemption the safety one is squarely inside the
    // window that gets deleted.
    $safety = Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $target->application_id,
        'type' => 'full',
        'is_safety' => true,
        'status' => BackupStatus::Verified,
        'manifest' => ['key' => 'a/safety.tar.gz'],
    ]);

    foreach (['a/one.tar.gz', 'a/two.tar.gz', 'a/three.tar.gz'] as $key) {
        Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $target->application_id,
            'type' => 'full',
            'status' => BackupStatus::Verified,
            'manifest' => ['key' => $key],
        ]);
    }

    $current = Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $target->application_id,
        'type' => 'full',
        'status' => BackupStatus::Running,
    ]);

    // A disk that reports every object present and records what it deletes, so
    // the assertion is about real prune decisions rather than a no-op.
    $deleted = [];
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->andReturn(true);
    $filesystem->shouldReceive('delete')->andReturnUsing(function ($key) use (&$deleted) {
        $deleted[] = $key;

        return true;
    });

    $disks = Mockery::mock(DestinationDisk::class);
    $disks->shouldReceive('for')->andReturn($filesystem);

    (new PruneOldBackups($disks))->run(
        new BackupContext($current, $target->fresh(), sys_get_temp_dir())
    );

    expect(Backup::whereKey($safety->getKey())->exists())->toBeTrue()
        ->and($deleted)->not->toContain('a/safety.tar.gz')
        // And it genuinely pruned something, or the assertion above passes for
        // the wrong reason — a prune that did nothing proves nothing.
        ->and($deleted)->not->toBeEmpty();
});
