<?php

use App\Enums\BackupStatus;
use App\Exceptions\UploadStalled;
use App\Jobs\RunBackup;
use App\Jobs\RunRestore;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\StaleBackupReaper;
use App\Services\Server\Backups\Steps\UploadArtifact;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\UploadProgressReporter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A disk that behaves like the real one in the only respect under test.
 *
 * `DestinationDisk::for()` is typed to return `Filesystem`, so a bare
 * anonymous class is rejected by PHP before any assertion runs — the fake has
 * to satisfy the contract it is standing in for.
 *
 * Named `fakeUploadDisk` rather than `fakeDisk`: Pest loads every test file
 * into one process, so a helper here shares a global namespace with every
 * other suite, and `DiskCleanerTest` already owns that shorter name.
 */
function fakeUploadDisk(Closure $writeStream): DestinationDisk
{
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('writeStream')->andReturnUsing($writeStream);

    $disks = Mockery::mock(DestinationDisk::class);
    $disks->shouldReceive('for')->andReturn($filesystem);

    return $disks;
}

uses(RefreshDatabase::class);

/*
 * A 24 GB backup to Google Drive could not succeed, and when it failed nobody
 * could tell why.
 *
 * Three separate causes, all proven on a live box on 2026-09-21:
 *
 *  - `RunBackup::$timeout` was a hardcoded 3600. The archive took 13 minutes to
 *    build and over three hours to upload, so the worker killed the job at the
 *    hour mark every single attempt. The run was not slow; it was impossible.
 *  - Nothing recorded progress, so the row said `upload_artifact` for the whole
 *    time and a healthy transfer was indistinguishable from a dead socket. It
 *    was reported as a hang twice, and answering it needed `strace` on the box.
 *  - A stalled upload had no ceiling of its own, so it held the only queue
 *    worker until the job timeout, with every other backup queued behind it.
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
    // The literal that made large sites impossible. Asserting on the *value*
    // rather than merely "is configurable" because the bug was the number, and
    // a config key that still defaults to 3600 would pass a weaker test while
    // failing every real 24 GB backup exactly as before.
    expect((new RunBackup(1))->timeout)->toBe(21600)
        ->and((new RunRestore(1, 1))->timeout)->toBe(21600);
});

it('keeps retry_after above the job timeout so a slow backup is not run twice', function () {
    // Laravel hands a job to a second worker once `retry_after` elapses,
    // whether or not the first has finished. With a 6h timeout and the old
    // 4200s retry_after, every backup over 70 minutes would have been archived
    // twice, concurrently, to one key — silent corruption rather than an error.
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

    // A fake disk that genuinely drains the handle, because the number under
    // test is produced by *reading*. A fake that ignores the stream would
    // assert against a transfer that never happened and pass while the filter
    // did nothing — the shape of mistake `feedback_a-static-fake-is-not-a-server`
    // records.
    $disk = fakeUploadDisk(function (string $key, $handle): void {
        while (! feof($handle)) {
            fread($handle, 8_192);
        }
    });

    (new UploadArtifact($disk))->run($context);

    expect($backup->fresh()->bytes_transferred)->toBe(64_000);

    @unlink($archive);
});

it('names a stalled upload instead of reporting it as a broken destination', function () {
    $backup = progressBackup();

    $archive = tempnam(sys_get_temp_dir(), 'arc');
    file_put_contents($archive, 'payload');

    $context = new BackupContext($backup, $backup->target, dirname($archive));
    $context->archivePath = $archive;

    // The wording cURL produces for the low-speed abort, wrapped the way
    // Flysystem wraps every adapter failure — the string is two links down,
    // which is exactly why the runner cannot match on it directly.
    $disk = fakeUploadDisk(function (): void {
        throw new RuntimeException(
            'Unable to write file at location: x.',
            previous: new RuntimeException(
                'cURL error 28: Operation too slow. Less than 1 bytes/sec transferred the last 1200 seconds'
            ),
        );
    });

    expect(fn () => (new UploadArtifact($disk))->run($context))
        ->toThrow(UploadStalled::class);

    @unlink($archive);
});

it('lets an ordinary upload failure through unchanged', function () {
    $backup = progressBackup();

    $archive = tempnam(sys_get_temp_dir(), 'arc');
    file_put_contents($archive, 'payload');

    $context = new BackupContext($backup, $backup->target, dirname($archive));
    $context->archivePath = $archive;

    // A full Drive is not a stall, and dressing it as one would tell the
    // operator to wait for the next run instead of going to free up space.
    $disk = fakeUploadDisk(function (): void {
        throw new RuntimeException('storageQuotaExceeded');
    });

    expect(fn () => (new UploadArtifact($disk))->run($context))
        ->toThrow(RuntimeException::class, 'storageQuotaExceeded');

    @unlink($archive);
});

it('frees a crashed target as soon as its heartbeat goes cold', function () {
    $backup = progressBackup();

    // Killed one minute in, having uploaded a little. Before the heartbeat the
    // reaper measured from `started_at` against the job's whole timeout, so
    // this row — and therefore the site — stayed locked for six hours.
    $backup->forceFill([
        'bytes_transferred' => 500,
        'progress_at' => now()->subSeconds(StaleBackupReaper::heartbeatGraceSeconds() + 60),
    ])->save();

    expect(app(StaleBackupReaper::class)->isStale($backup->fresh()))->toBeTrue();
});

it('leaves a slow but living upload alone', function () {
    $backup = progressBackup();

    // Hours old and far past the old `started_at` bound, but still moving.
    // This is the case a wall-clock timeout cannot tell from the one above,
    // and killing it is how large backups were made impossible.
    $backup->forceFill([
        'started_at' => now()->subHours(5),
        'bytes_transferred' => 9_000_000_000,
        'progress_at' => now()->subSeconds(10),
    ])->save();

    expect(app(StaleBackupReaper::class)->isStale($backup->fresh()))->toBeFalse();
});
