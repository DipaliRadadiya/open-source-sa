<?php

use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\Steps\VerifyArtifact;
use App\Services\Server\Backups\Storage\DestinationDisk;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * The value here is the whole chain: dump → archive → upload → verify → prune.
 * Each step in isolation proves little; what matters is that a run produces an
 * artefact that is actually on the destination, at the size we sent, and that
 * retention only ever removes backups after a newer one is verified.
 */

beforeEach(function () {
    $this->fakeDisk = Storage::fake('destination');

    // The disk is built per-destination and never registered globally, so the
    // fake has to be injected the same way production builds the real one.
    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
        fn (array $config) => $this->fakeDisk,
    ));

    $this->siteRoot = storage_path('framework/testing/site-'.uniqid());
    File::ensureDirectoryExists($this->siteRoot.'/public');
    File::put($this->siteRoot.'/public/index.php', '<?php echo "site";');

    $systemUser = SystemUser::create([
        'username' => 'backupuser',
        'home_path' => dirname($this->siteRoot),
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Backed Up',
        'slug' => 'backed-up',
        'domain' => basename($this->siteRoot),
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    $this->destination = StorageDestination::create([
        'name' => 'Backups',
        'endpoint' => '',
        'region' => 'us-east-1',
        'bucket' => 'backups',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->siteRoot);
});

function backupTarget(array $overrides = []): BackupTarget
{
    return BackupTarget::create(array_merge([
        'application_id' => test()->application->id,
        'storage_destination_id' => test()->destination->id,
        'type' => 'filesystem',
        'retention_count' => 3,
        'enabled' => true,
        'frequency' => 'daily',
    ], $overrides));
}

/** Make `tar` produce a real file so the archive step behaves like the real one. */
function fakeTar(): void
{
    Process::fake(function ($process) {
        $command = $process->command;

        if (($command[0] ?? null) === 'tar') {
            $archive = $command[2] ?? null;
            if (is_string($archive)) {
                file_put_contents($archive, str_repeat('x', 2048));
            }
        }

        return Process::result(exitCode: 0);
    });
}

it('runs every step and verifies the artefact is really on the destination', function () {
    fakeTar();

    $backup = app(BackupRunner::class)->run(backupTarget());

    expect($backup->status)->toBe(BackupStatus::Verified)
        ->and($backup->verified_at)->not->toBeNull()
        ->and($backup->size_bytes)->toBe(2048)
        // `reason` carries the in-progress step; a completed run must not
        // leave the last step name sitting in it, which reads as a failure.
        ->and($backup->reason)->toBeNull();

    $key = $backup->manifest['key'];
    $this->fakeDisk->assertExists($key);
    expect($backup->manifest['verified_bytes'])->toBe(2048);
});

it('fails the backup when the destination holds a different size', function () {
    fakeTar();

    $target = backupTarget();
    $backup = Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $this->application->id,
        'type' => 'filesystem',
        'status' => BackupStatus::Verifying,
    ]);

    $context = new BackupContext($backup, $target, storage_path('framework/testing'));
    $context->remoteKey = 'backups/short.tar.gz';
    // What we believe we uploaded...
    $context->sizeBytes = 2048;
    // ...against what actually landed. A truncated upload does not throw, so
    // without this check the panel reports a backup that cannot be restored.
    $this->fakeDisk->put($context->remoteKey, 'truncated');

    expect(fn () => app(VerifyArtifact::class)->run($context))
        ->toThrow(RuntimeException::class);
});

it('records which step failed, not just that something did', function () {
    Process::fake(['*' => Process::result(errorOutput: 'tar: no space left on device', exitCode: 2)]);

    $backup = app(BackupRunner::class)->run(backupTarget());

    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->reason)->toBe('archive_files');
});

it('leaves no local artefacts behind, even on failure', function () {
    Process::fake(['*' => Process::result(exitCode: 2)]);

    app(BackupRunner::class)->run(backupTarget());

    // A dump and an archive left after a failure fill the disk the next
    // attempt needs — and the next attempt is minutes away.
    expect(File::directories(config('server.backups.working_dir')))->toBeEmpty();
});

it('marks the target as run even when the backup failed', function () {
    Process::fake(['*' => Process::result(exitCode: 2)]);
    $target = backupTarget();

    app(BackupRunner::class)->run($target);

    // Otherwise it stays due and retries every tick, turning one broken
    // backup into a loop.
    expect($target->refresh()->last_run_at)->not->toBeNull();
});

describe('retention', function () {
    it('keeps only the newest N verified backups', function () {
        fakeTar();
        $target = backupTarget(['retention_count' => 2]);

        $runner = app(BackupRunner::class);
        $first = $runner->run($target);
        $second = $runner->run($target);
        $third = $runner->run($target);

        expect(Backup::count())->toBe(2)
            ->and(Backup::find($first->id))->toBeNull()
            ->and(Backup::find($second->id))->not->toBeNull()
            ->and(Backup::find($third->id))->not->toBeNull();

        // The pruned artefact must go from the destination too, or retention
        // controls the panel's list and not the storage bill.
        $this->fakeDisk->assertMissing($first->manifest['key']);
        $this->fakeDisk->assertExists($third->manifest['key']);
    });

    it('never prunes for a backup that then failed', function () {
        fakeTar();
        $target = backupTarget(['retention_count' => 1]);
        $runner = app(BackupRunner::class);

        $good = $runner->run($target);

        Process::fake(['*' => Process::result(exitCode: 2)]);
        $runner->run($target);

        // Pruning before verifying would have deleted the only good backup to
        // make room for one that never arrived.
        expect(Backup::find($good->id))->not->toBeNull();
        $this->fakeDisk->assertExists($good->manifest['key']);
    });

    it('does not count failed backups towards retention', function () {
        fakeTar();
        $target = backupTarget(['retention_count' => 2]);
        $runner = app(BackupRunner::class);

        $first = $runner->run($target);

        Process::fake(['*' => Process::result(exitCode: 2)]);
        $runner->run($target);

        fakeTar();
        $runner->run($target);

        // Two verified plus one failed. If the failed one counted, the first
        // good backup would have been pruned — quietly eroding the retention
        // the user asked for.
        expect(Backup::find($first->id))->not->toBeNull();
    });
});
