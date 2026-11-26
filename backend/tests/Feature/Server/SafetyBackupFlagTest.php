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
 * The flag must be set when the row is created, not after `BackupRunner::run()` returns: the
 * archive and upload can take an hour, and a worker that dies in that window leaves a row with
 * `is_safety = false`.
 *
 * `PruneOldBackups` protects these rows with `where('is_safety', false)`, so an unflagged row
 * is later deleted along with its archive in the bucket.
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
 * A step that reads the row back from the database mid-run, proving the flag is there during
 * the work rather than added at the end.
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
            // Read back, not taken from the model: the prune query runs against the table.
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

    // If this is false, a worker dying mid-upload leaves an unprotected safety backup.
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

    // Retention is 2 and all three rows are older than the pruning run, so without the exemption
    // the safety one would be deleted.
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

    // A disk that reports every object present and records what it deletes.
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
        // It really pruned something, or the assertion above proves nothing.
        ->and($deleted)->not->toBeEmpty();
});
