<?php

use App\Actions\Server\Backup\DeleteBackup;
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
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * The value is the whole chain: dump, archive, upload, verify, prune. A run must leave an artefact
 * on the destination at the size we sent, and retention may only remove backups after a newer
 * one is verified.
 */

beforeEach(function () {
    $this->fakeDisk = Storage::fake('destination');

    // The disk is built per-destination and never registered globally, so the
    // fake has to be injected the same way production builds the real one.
    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
        app(StorageDriverFactory::class),
        fn (array $config) => $this->fakeDisk,
    ));

    // Built where the panel actually looks: `{home}/{slug}/public_html`.
    $this->home = storage_path('framework/testing/home-'.uniqid());
    $this->siteRoot = $this->home.'/backed-up/public_html';
    File::ensureDirectoryExists($this->siteRoot);
    File::put($this->siteRoot.'/index.php', '<?php echo "site";');

    $systemUser = SystemUser::create([
        'username' => 'backupuser',
        'home_path' => $this->home,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Backed Up',
        'slug' => 'backed-up',
        'domain' => 'backed-up.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    $this->destination = StorageDestination::create([
        'name' => 'Backups',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'backups', 'access_key' => 'key', 'secret_key' => 'secret'],
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->home);
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
            $archive = tarArchivePath($command);
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

    it('never prunes regular backups for a restore\'s safety backup', function () {
        // A safety backup is not one of the N the user keeps: pruning for it must not delete the
        // oldest regular backup (it may be the one being restored).
        fakeTar();
        $target = backupTarget(['retention_count' => 2]);
        $runner = app(BackupRunner::class);

        $older = $runner->run($target);
        $newer = $runner->run($target);

        $safety = $runner->run($target, isSafety: true);

        expect($safety->is_safety)->toBeTrue()
            ->and(Backup::find($older->id))->not->toBeNull()
            ->and(Backup::find($newer->id))->not->toBeNull();
        $this->fakeDisk->assertExists($older->manifest['key']);
    });

    it('stays verified when an old backup\'s destination can no longer be reached', function () {
        // The new backup is already uploaded and verified when pruning runs. A destination that has
        // gone away must not fail the backup that just succeeded.
        fakeTar();
        $target = backupTarget(['retention_count' => 1]);
        $runner = app(BackupRunner::class);

        $old = $runner->run($target);

        $gone = StorageDestination::create([
            'name' => 'Gone',
            'provider' => 's3',
            'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'gone', 'access_key' => 'key', 'secret_key' => 'secret'],
        ]);
        $old->forceFill(['storage_destination_id' => $gone->id])->save();

        $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
            app(StorageDriverFactory::class),
            fn (array $config) => ($config['bucket'] ?? null) === 'gone'
                ? throw new RuntimeException('destination unreachable')
                : $this->fakeDisk,
        ));

        $new = app(BackupRunner::class)->run($target);

        expect($new->status)->toBe(BackupStatus::Verified)
            // Could not be deleted, so it is kept rather than half-forgotten.
            ->and(Backup::find($old->id))->not->toBeNull();
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

        // Two verified plus one failed. If the failed one counted, the first good backup would be
        // pruned, eroding retention.
        expect(Backup::find($first->id))->not->toBeNull();
    });
});

describe('the archive name', function () {
    /*
     * A Drive destination writes into somebody's *personal* Google Drive, so this path is what they
     * find next to their photos: one folder per application, archives directly inside it.
     */
    it('puts the archive in a folder named after the application', function () {
        fakeTar();

        $backup = app(BackupRunner::class)->run(backupTarget());
        $key = $backup->manifest['key'];

        expect($key)->toStartWith('backed-up.example.com/')
            // No hardcoded `backups/` wrapper: the destination has its own key prefix field.
            ->and($key)->not->toStartWith('backups/')
            // The date belongs in the name, where it sorts, not in a folder holding one file.
            ->and(substr_count($key, '/'))->toBe(1);
    });

    /*
     * A raw id (`75599625-…tar.gz`) tells its owner nothing: a backup should say what it is
     * without being opened.
     */
    it('names the archive so a human can read it', function () {
        fakeTar();

        $backup = app(BackupRunner::class)->run(backupTarget());
        $name = basename($backup->manifest['key']);

        expect($name)->toStartWith($backup->created_at->format('Y-m-d-Hi'))
            // The uid still ends it: that stops two panels sharing one destination from
            // overwriting each other, and unlike an autoincrement id it survives a reinstall.
            ->and($name)->toContain($backup->uid)
            ->and($name)->toEndWith('.tar.gz');
    });

    it('does not reuse a key when the id counter starts again', function () {
        // The regression: the key used to end in the backup id, which only means something inside
        // one panel's database. After a reinstall the counting restarts over a bucket that still
        // holds the old archives. Simulated by giving a second backup the first one's id.
        fakeTar();

        $target = backupTarget();

        $first = app(BackupRunner::class)->run($target);
        $firstKey = $first->manifest['key'];
        $firstId = $first->id;

        expect($this->fakeDisk->get($firstKey))->toHaveLength(2048);

        // The panel is reinstalled: its history is gone, the bucket is not.
        Backup::query()->delete();
        DB::table('sqlite_sequence')->where('name', 'backups')->update(['seq' => $firstId - 1]);

        $second = app(BackupRunner::class)->run($target);

        expect($second->id)->toBe($firstId)
            ->and($second->manifest['key'])->not->toBe($firstKey);

        // Both archives are still there; under the old scheme the second overwrote the first.
        $this->fakeDisk->assertExists($firstKey);
        $this->fakeDisk->assertExists($second->manifest['key']);
    });

    it('gives every backup a uid without anyone having to ask', function () {
        $target = backupTarget();

        $one = Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $this->application->id,
            'type' => 'filesystem',
            'status' => BackupStatus::Running,
        ]);
        $two = Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $this->application->id,
            'type' => 'filesystem',
            'status' => BackupStatus::Running,
        ]);

        expect($one->uid)->not->toBeEmpty()
            ->and($two->uid)->not->toBeEmpty()
            ->and($one->uid)->not->toBe($two->uid);
    });

    it('cannot be chosen by the caller', function () {
        // Not fillable on purpose: this is the field that stops one archive
        // overwriting another, so nothing outside the model picks it.
        $target = backupTarget();

        $backup = Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $this->application->id,
            'type' => 'filesystem',
            'status' => BackupStatus::Running,
            'uid' => 'chosen-by-the-caller',
        ]);

        expect($backup->uid)->not->toBe('chosen-by-the-caller');
    });

    it('still finds an archive written under the old id-based scheme', function () {
        // Nothing recomputes the key (every reader takes it off the row), so older backups
        // resolve as before.
        $target = backupTarget();
        $legacyKey = 'backups/backed-up.example.com/2026-01-01/7.tar.gz';
        $this->fakeDisk->put($legacyKey, str_repeat('x', 2048));

        $backup = Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $this->application->id,
            'type' => 'filesystem',
            'status' => BackupStatus::Verified,
            'manifest' => ['key' => $legacyKey],
        ]);

        app(DeleteBackup::class)->execute($backup);

        $this->fakeDisk->assertMissing($legacyKey);
        expect(Backup::find($backup->id))->toBeNull();
    });
});
