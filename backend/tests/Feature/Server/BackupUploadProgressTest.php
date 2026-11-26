<?php

use App\Contracts\BackupStep;
use App\Contracts\StorageDriver;
use App\Enums\BackupStatus;
use App\Exceptions\UploadStalled;
use App\Http\Resources\BackupResource;
use App\Jobs\RunBackup;
use App\Jobs\RunRestore;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\StaleBackupReaper;
use App\Services\Server\Backups\Steps\UploadArtifact;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use App\Services\Server\Backups\UploadProgressReporter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A disk fake that satisfies the `Filesystem` return type of `DestinationDisk::for()`.
 *
 * Named `fakeUploadDisk`: Pest shares one global namespace across test files and
 * `DiskCleanerTest` already owns `fakeDisk`.
 */
function fakeUploadDisk(Closure $writeStream): DestinationDisk
{
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('writeStream')->andReturnUsing($writeStream);

    $disks = Mockery::mock(DestinationDisk::class);
    $disks->shouldReceive('for')->andReturn($filesystem);

    return $disks;
}

/**
 * A driver factory whose driver declines the resumable path, so the `writeStream()`
 * fallback runs, as with S3/FTP/SFTP in production.
 */
function decliningDrivers(): StorageDriverFactory
{
    $driver = Mockery::mock(StorageDriver::class);
    $driver->shouldReceive('uploadFrom')->andReturn(false);

    $factory = Mockery::mock(StorageDriverFactory::class);
    $factory->shouldReceive('for')->andReturn($driver);

    return $factory;
}

uses(RefreshDatabase::class);

/*
 * Large uploads: the job timeout must not cap a long upload, progress and a heartbeat must be
 * recorded, and a stalled upload needs its own ceiling so it does not hold the only queue worker.
 */

function progressBackup(): Backup
{
    $application = Application::factory()->create();

    $destination = StorageDestination::create([
        'name' => 'Offsite',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'backups', 'access_key' => 'k', 'secret_key' => 's'],
    ]);

    $target = BackupTarget::create([
        'application_id' => $application->id,
        'storage_destination_id' => $destination->id,
        'type' => 'full',
        'retention_count' => 7,
        'frequency' => 'daily',
    ]);

    return Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $application->id,
        'type' => 'full',
        'status' => BackupStatus::Running,
        'started_at' => now(),
    ]);
}

it('does not cap a backup at the old hardcoded hour', function () {
    // Assert the value, not just "is configurable": a key still defaulting to 3600 would pass.
    expect((new RunBackup(1))->timeout)->toBe(21600)
        ->and((new RunRestore(1, 1))->timeout)->toBe(21600);
});

it('keeps retry_after above the job timeout so a slow backup is not run twice', function () {
    // Laravel hands a job to a second worker once `retry_after` elapses, finished or not: a
    // shorter value would archive one backup twice, concurrently, to one key.
    expect(config('queue.connections.redis.retry_after'))
        ->toBeGreaterThan(config('server.backups.job_timeout'));
});

it('records how far an upload has got, and its heartbeat', function () {
    $backup = progressBackup();

    $reporter = new UploadProgressReporter($backup, total: 1_000);
    $reporter->advance(400);
    $reporter->flush();

    $backup->refresh();

    expect($backup->bytes_transferred)->toBe(400)
        ->and($backup->bytes_total)->toBe(1_000)
        ->and($backup->progress_at)->not->toBeNull();
});

it('counts every byte the adapter reads off the archive', function () {
    $backup = progressBackup();

    $archive = tempnam(sys_get_temp_dir(), 'arc');
    file_put_contents($archive, str_repeat('x', 64_000));

    $context = new BackupContext($backup, $backup->target, dirname($archive));
    $context->archivePath = $archive;

    // The fake must really drain the handle: the byte count is produced by reading.
    $disk = fakeUploadDisk(function (string $key, $handle): void {
        while (! feof($handle)) {
            fread($handle, 8_192);
        }
    });

    (new UploadArtifact($disk, decliningDrivers()))->run($context);

    expect($backup->fresh()->bytes_transferred)->toBe(64_000);

    @unlink($archive);
});

it('names a stalled upload instead of reporting it as a broken destination', function () {
    $backup = progressBackup();

    $archive = tempnam(sys_get_temp_dir(), 'arc');
    file_put_contents($archive, 'payload');

    $context = new BackupContext($backup, $backup->target, dirname($archive));
    $context->archivePath = $archive;

    // cURL's low-speed abort wording, wrapped as Flysystem wraps adapter failures: the string
    // sits two links down the chain, so the runner cannot match it directly.
    $disk = fakeUploadDisk(function (): void {
        throw new RuntimeException(
            'Unable to write file at location: x.',
            previous: new RuntimeException(
                'cURL error 28: Operation too slow. Less than 1 bytes/sec transferred the last 1200 seconds'
            ),
        );
    });

    expect(fn () => (new UploadArtifact($disk, decliningDrivers()))->run($context))
        ->toThrow(UploadStalled::class);

    @unlink($archive);
});

it('lets an ordinary upload failure through unchanged', function () {
    $backup = progressBackup();

    $archive = tempnam(sys_get_temp_dir(), 'arc');
    file_put_contents($archive, 'payload');

    $context = new BackupContext($backup, $backup->target, dirname($archive));
    $context->archivePath = $archive;

    // A full Drive is not a stall: reporting it as one would tell the operator to wait, not free space.
    $disk = fakeUploadDisk(function (): void {
        throw new RuntimeException('storageQuotaExceeded');
    });

    expect(fn () => (new UploadArtifact($disk, decliningDrivers()))->run($context))
        ->toThrow(RuntimeException::class, 'storageQuotaExceeded');

    @unlink($archive);
});

it('frees a crashed target as soon as its heartbeat goes cold', function () {
    $backup = progressBackup();

    // A crashed run is judged by its heartbeat, not by `started_at` against the whole job timeout.
    $backup->forceFill([
        'bytes_transferred' => 500,
        'progress_at' => now()->subSeconds(StaleBackupReaper::heartbeatGraceSeconds() + 60),
    ])->save();

    expect(app(StaleBackupReaper::class)->isStale($backup->fresh()))->toBeTrue();
});

it('leaves a slow but living upload alone', function () {
    $backup = progressBackup();

    // Hours old but still moving: a wall-clock timeout cannot tell this from a crashed run.
    $backup->forceFill([
        'started_at' => now()->subHours(5),
        'bytes_transferred' => 9_000_000_000,
        'progress_at' => now()->subSeconds(10),
    ])->save();

    expect(app(StaleBackupReaper::class)->isStale($backup->fresh()))->toBeFalse();
});

it('releases the uniqueness lock at pickup so a killed worker strands nothing', function () {
    // Otherwise a `kill -9` holds the lock for the job's full TTL and later dispatches are
    // silently discarded while the API still answers 202.
    expect(new RunBackup(1))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and(new RunRestore(1, 1))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});

it('refuses to start a second run for a target that is already live', function () {
    $backup = progressBackup();

    // With the lock released at pickup, two jobs for one target can queue. This check runs on
    // the worker about to write, so it cannot be raced.
    $backup->forceFill(['progress_at' => now()])->save();

    (new RunBackup($backup->backup_target_id))->handle(
        app(BackupRunner::class),
        app(ActivityLogger::class),
    );

    // Still exactly one run: the job returned without creating a second.
    expect(Backup::where('backup_target_id', $backup->backup_target_id)->count())->toBe(1);
});

it('does not stretch the no-heartbeat lockout when the job timeout is raised', function () {
    // The bound must not follow the job timeout, or a crash during archiving would lock its
    // target for hours instead of 65 minutes.
    expect(StaleBackupReaper::staleAfterSeconds())->toBe(3900)
        ->and(StaleBackupReaper::staleAfterSeconds())
        ->toBeLessThan(config('server.backups.job_timeout'));
});

/**
 * Run the upload step against a disk that just reports the ceiling it saw.
 *
 * Baselines must sit well above the suite's own footprint: Pest runs every test in one process,
 * and `ini_set()` to a lower limit fails outright.
 */
function ceilingSeenDuringUpload(string $baseline, string $configured): array
{
    $original = ini_get('memory_limit');

    config()->set('server.backups.upload_memory_limit', $configured);
    ini_set('memory_limit', $baseline);

    $backup = progressBackup();
    $archive = tempnam(sys_get_temp_dir(), 'arc');
    file_put_contents($archive, 'payload');

    $context = new BackupContext($backup, $backup->target, dirname($archive));
    $context->archivePath = $archive;

    $seen = null;
    (new UploadArtifact(fakeUploadDisk(function () use (&$seen) {
        $seen = ini_get('memory_limit');
    }), decliningDrivers()))->run($context);

    $after = ini_get('memory_limit');

    ini_set('memory_limit', $original);
    @unlink($archive);

    return ['during' => $seen, 'after' => $after];
}

it('raises the memory ceiling for the upload and puts it back afterwards', function () {
    // Google's MediaFileUpload base64-encodes an archive up to 100 MB whole, in memory; a fatal
    // there records no reason and leaves the row `running`.
    $result = ceilingSeenDuringUpload(baseline: '512M', configured: '1024M');

    expect($result['during'])->toBe('1024M')
        // Restored: a worker outlives one job.
        ->and($result['after'])->toBe('512M');
});

it('never lowers a ceiling the host already set higher', function () {
    // Writing the configured value unconditionally would reduce memory on a generous host.
    expect(ceilingSeenDuringUpload(baseline: '2048M', configured: '256M')['during'])
        ->toBe('2048M');
});

it('leaves an unlimited host unlimited', function () {
    expect(ceilingSeenDuringUpload(baseline: '-1', configured: '768M')['during'])
        ->toBe('-1');
});

/*
 * A failed backup reports the storage's error category (as the destination test does), not
 * only the step that stopped.
 */
class BackupErrorClassUploadStep implements BackupStep
{
    public function key(): string
    {
        return 'upload_artifact';
    }

    public function appliesTo(BackupContext $context): bool
    {
        return true;
    }

    public function run(BackupContext $context): void
    {
        throw new RuntimeException('Error executing "PutObject": 403 Forbidden (client): InvalidAccessKeyId - The AWS Access Key Id you provided does not exist in our records.');
    }

    public function cleanup(BackupContext $context): void {}
}

class BackupErrorClassDumpStep extends BackupErrorClassUploadStep
{
    public function key(): string
    {
        return 'dump_database';
    }
}

it('says why the storage refused a backup, not only where it stopped', function () {
    config(['server.backups.steps' => [BackupErrorClassUploadStep::class]]);
    $target = progressBackup()->target;

    $backup = (new BackupRunner)->run($target);

    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->reason)->toBe('upload_artifact')
        ->and($backup->error_class)->toBe('invalid_credentials')
        ->and(BackupResource::make($backup)->resolve()['error_class_title'])->toBe(__('storage.test.invalid_credentials'));
});

it('names no storage cause for a step that never reached the storage', function () {
    config(['server.backups.steps' => [BackupErrorClassDumpStep::class]]);
    $target = progressBackup()->target;

    $backup = (new BackupRunner)->run($target);

    expect($backup->reason)->toBe('dump_database')
        ->and($backup->error_class)->toBeNull();
});
