<?php

use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\BackupRoot;
use App\Services\Server\Backups\Steps\ArchiveFiles;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * `tar -czf` is gzip level 6 on exactly one core.
 *
 * Measured on a 103 GB site, 8 cores: 67 minutes, gzip pinned at 99.5% of one
 * core with seven idle, and the output was 99.46% of the input. An hour of CPU
 * for a 0.54% saving, because the data was already-compressed media.
 *
 * `pigz` is the same format across all cores. The artefact does not change —
 * `tar -tzf`, the verify step and every archive already sitting in a bucket
 * keep working, confirmed against GNU tar 1.35 before this shipped.
 *
 * The fallback is the load-bearing part: the updater ships code, never
 * packages, so a build that *required* pigz would work on every fresh install
 * and break every existing one.
 */

function archiveContext(): BackupContext
{
    $application = Application::factory()->create();

    // Unique per call: a test that exercises the step twice (the clamp one
    // does) would otherwise collide on storage_destinations.name.
    $destination = StorageDestination::create([
        'name' => 'gDrive-'.Str::random(8),
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
    ]);

    $target = BackupTarget::create([
        'application_id' => $application->id,
        'storage_destination_id' => $destination->id,
        'type' => 'database',
        'retention_count' => 2,
        'frequency' => 'manual',
    ]);

    $backup = Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $application->id,
        'type' => 'database',
        'status' => BackupStatus::Running,
    ]);

    return new BackupContext($backup, $target->fresh(), sys_get_temp_dir());
}

/**
 * Run the step against a stubbed ServerOps and hand back the argv it built.
 *
 * `ServerOps` is mocked rather than the Process facade: the step is what
 * assembles the command, and mocking one layer lower would test Laravel's
 * process plumbing instead. The stub also has to leave a real file behind —
 * the step does `is_file($archive)` afterwards, so a fake that records the
 * call and writes nothing would assert against the error path rather than the
 * command. See `feedback_a-static-fake-is-not-a-server`.
 *
 * @return list<string>
 */
function capturedTarCommand(): array
{
    $context = archiveContext();
    $archive = $context->workingDirectory.'/backup.tar.gz';
    @unlink($archive);

    $seen = [];

    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('run')->andReturnUsing(function (array $command) use (&$seen, $archive) {
        $seen = $command;
        touch($archive);

        return new ServerOpsResult(ok: true, reference: 'test');
    });

    try {
        (new ArchiveFiles($ops, app(BackupRoot::class)))->run($context);
    } finally {
        @unlink($archive);
    }

    return $seen;
}

it('compresses with pigz when it is installed', function () {
    config()->set('server.backups.compressor', 'pigz');
    config()->set('server.backups.compression_level', 1);

    $command = capturedTarCommand();

    expect($command)->toContain('--use-compress-program=pigz -1')
        // `-cf`, not `-czf`: `-z` would force gzip and ignore the program.
        ->and($command)->toContain('-cf')
        ->and($command)->not->toContain('-czf');
});

it('falls back to gzip when pigz is absent', function () {
    // The case that matters on upgrade. The updater ships code, never
    // packages, so an existing panel gets this build with no pigz on disk —
    // and it must still take backups.
    config()->set('server.backups.compressor', 'gzip');
    config()->set('server.backups.compression_level', 1);

    expect(capturedTarCommand())->toContain('--use-compress-program=gzip -1');
});

it('clamps a nonsense level instead of failing every backup', function () {
    // gzip and pigz both reject anything outside 1-9. A typo in an env file
    // should not take the whole box's backups down with a message about
    // command-line syntax.
    config()->set('server.backups.compressor', 'gzip');
    config()->set('server.backups.compression_level', 99);

    expect(capturedTarCommand())->toContain('--use-compress-program=gzip -9');

    config()->set('server.backups.compression_level', 0);

    expect(capturedTarCommand())->toContain('--use-compress-program=gzip -1');
});

it('does not cap the archive step at its own hidden hour', function () {
    // A second hardcoded 3600 lived here, independent of the job timeout. A
    // 103 GB site takes ~67 minutes to archive with plain gzip, so raising the
    // job ceiling to six hours still left the *step* to be killed at sixty
    // minutes — the same backup failing one minute later for a different
    // reason.
    $source = file_get_contents(base_path('app/Services/Server/Backups/Steps/ArchiveFiles.php'));

    expect($source)->not->toMatch('/timeout:\s*3600/')
        ->and($source)->toContain("config('server.backups.job_timeout'");

    foreach ([
        'app/Services/Server/Restores/Steps/ExtractArchive.php',
        'app/Services/Server/Restores/Steps/VerifyDownload.php',
    ] as $path) {
        expect(file_get_contents(base_path($path)))
            ->toContain("config('server.backups.job_timeout'");
    }
});
