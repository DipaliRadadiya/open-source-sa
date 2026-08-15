<?php

use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Restores\RestoreRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Restore is the only operation in the panel that destroys data, so the tests
 * that matter are the ones about failure: what the site looks like when the
 * download is truncated, when the safety backup cannot be taken, when the
 * archive turns out to hold something else. In every one of those the correct
 * answer is "exactly as it was before the button was pressed".
 */

beforeEach(function () {
    $this->fakeDisk = Storage::fake('destination');

    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
        fn (array $config) => $this->fakeDisk,
    ));

    $this->home = storage_path('framework/testing/home-'.uniqid());
    $this->domain = 'restore-me.test';
    // `{home}/{slug}/public_html`, which is where the panel resolves a document
    // root — not `{home}/{domain}`. Building it by domain is the same mistake
    // the PHP-version screen shipped, and it left every restore here failing at
    // the first step for want of a directory.
    $this->siteRoot = $this->home.'/restore-me/public_html';

    File::ensureDirectoryExists($this->siteRoot);
    File::put($this->siteRoot.'/index.php', '<?php echo "live";');

    $systemUser = SystemUser::create(['username' => 'restoreuser', 'home_path' => $this->home]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Restore Me',
        'slug' => 'restore-me',
        'domain' => $this->domain,
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    $this->destination = StorageDestination::create([
        'name' => 'Backups', 'endpoint' => '', 'region' => 'us-east-1',
        'bucket' => 'backups', 'access_key' => 'key', 'secret_key' => 'secret',
    ]);

    // Not `$this->target`: HigherOrderTapProxy (what `test()` returns inside a
    // plain function) has its own `target` property, so `test()->target` would
    // silently hand back the test case instead of this row.
    $this->backupTarget = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $this->destination->id,
        'type' => 'filesystem',
        'retention_count' => 3,
        'enabled' => true,
        'frequency' => 'daily',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->home);
});

/**
 * A backup whose artefact really is on the fake destination, at the size the
 * row claims — the state a restore starts from.
 */
function storedBackup(int $bytes = 2048, string $status = 'verified'): Backup
{
    $key = 'backups/restore-me.test/2026-08-04/1.tar.gz';
    test()->fakeDisk->put($key, str_repeat('x', $bytes));

    return Backup::create([
        'backup_target_id' => test()->backupTarget->id,
        'application_id' => test()->application->id,
        'type' => 'filesystem',
        'status' => $status,
        'size_bytes' => $bytes,
        'manifest' => ['key' => $key],
        'verified_at' => now(),
    ]);
}

function restoreFor(Backup $backup, string $type = 'filesystem'): Restore
{
    return Restore::create([
        'backup_id' => $backup->id,
        'application_id' => test()->application->id,
        'type' => $type,
        'status' => RestoreStatus::Pending,
        'reference' => (string) Str::uuid(),
    ]);
}

/**
 * Stand in for tar and mv. `tar -xzf` produces the site directory the real one
 * would; `mv` actually moves, so the swap is exercised rather than mocked away.
 */
function fakeRestoreCommands(bool $tarFails = false): void
{
    Process::fake(function ($process) use ($tarFails) {
        $command = $process->command;
        $binary = $command[0] === 'sudo' ? ($command[2] ?? '') : $command[0];
        $args = $command[0] === 'sudo' ? array_slice($command, 2) : $command;

        if ($binary === 'tar' && ($args[1] ?? '') === '-xzf') {
            if ($tarFails) {
                return Process::result(errorOutput: 'tar: unexpected end of file', exitCode: 2);
            }

            $into = $args[4] ?? null;
            if (is_string($into)) {
                $site = $into.'/'.basename(test()->siteRoot);
                File::ensureDirectoryExists($site);
                File::put($site.'/index.php', '<?php echo "restored";');
            }
        }

        if ($binary === 'mv') {
            @rename($args[1], $args[2]);
        }

        return Process::result(exitCode: 0);
    });
}

/** Make the safety backup succeed by giving tar something to produce. */
function fakeSafetyBackupTar(): void
{
    Process::fake(function ($process) {
        $command = $process->command;
        $binary = $command[0] === 'sudo' ? ($command[2] ?? '') : $command[0];
        $args = $command[0] === 'sudo' ? array_slice($command, 2) : $command;

        if ($binary === 'tar' && ($args[1] ?? '') === '-czf') {
            file_put_contents($args[2], str_repeat('s', 4096));
        }

        if ($binary === 'tar' && ($args[1] ?? '') === '-xzf') {
            $into = $args[4] ?? null;
            if (is_string($into)) {
                $site = $into.'/'.basename(test()->siteRoot);
                File::ensureDirectoryExists($site);
                File::put($site.'/index.php', '<?php echo "restored";');
            }
        }

        if ($binary === 'mv') {
            @rename($args[1], $args[2]);
        }

        return Process::result(exitCode: 0);
    });
}

it('restores the files and leaves the previous ones recoverable', function () {
    fakeSafetyBackupTar();

    $restore = app(RestoreRunner::class)->run(restoreFor(storedBackup()));

    expect($restore->status)->toBe(RestoreStatus::Succeeded)
        ->and($restore->reason)->toBeNull()
        ->and(File::get($this->siteRoot.'/index.php'))->toContain('restored');

    // The previous directory is moved, never deleted — "the restore worked but
    // the site is wrong" has to stay recoverable.
    expect($restore->rollback_path)->not->toBeNull()
        ->and(is_dir($restore->rollback_path))->toBeTrue()
        ->and(File::get($restore->rollback_path.'/index.php'))->toContain('live');
});

it('takes a safety backup before it overwrites anything', function () {
    fakeSafetyBackupTar();

    $restore = app(RestoreRunner::class)->run(restoreFor(storedBackup()));

    $safety = Backup::find($restore->safety_backup_id);

    expect($safety)->not->toBeNull()
        ->and($safety->is_safety)->toBeTrue()
        ->and($safety->status)->toBe(BackupStatus::Verified);
});

describe('nothing is touched when it fails early', function () {
    it('stops when the download does not match the recorded size', function () {
        fakeSafetyBackupTar();

        // The row says 2048; the bucket holds far less. A truncated artefact
        // discovered after the swap would mean the site is simply gone.
        $backup = storedBackup();
        $this->fakeDisk->put($backup->manifest['key'], 'truncated');

        $restore = app(RestoreRunner::class)->run(restoreFor($backup));

        expect($restore->status)->toBe(RestoreStatus::Failed)
            ->and($restore->reason)->toBe('verify_download')
            ->and(File::get($this->siteRoot.'/index.php'))->toContain('live')
            // Not even a safety backup: nothing had to be protected.
            ->and($restore->safety_backup_id)->toBeNull();
    });

    it('stops when the artefact is missing from the destination', function () {
        $backup = storedBackup();
        $this->fakeDisk->delete($backup->manifest['key']);

        $restore = app(RestoreRunner::class)->run(restoreFor($backup));

        expect($restore->status)->toBe(RestoreStatus::Failed)
            ->and($restore->reason)->toBe('download_artifact')
            ->and(File::get($this->siteRoot.'/index.php'))->toContain('live');
    });

    it('stops when the safety backup cannot be taken', function () {
        // tar fails, so the safety backup fails. Continuing here would remove
        // the only way back at the exact moment it was about to be needed.
        Process::fake(function ($process) {
            $command = $process->command;
            $args = $command[0] === 'sudo' ? array_slice($command, 2) : $command;

            if (($args[0] ?? '') === 'tar' && ($args[1] ?? '') === '-tzf') {
                return Process::result(exitCode: 0);
            }

            return Process::result(errorOutput: 'no space left on device', exitCode: 2);
        });

        $restore = app(RestoreRunner::class)->run(restoreFor(storedBackup()));

        expect($restore->status)->toBe(RestoreStatus::Failed)
            ->and($restore->reason)->toBe('safety_backup')
            ->and(File::get($this->siteRoot.'/index.php'))->toContain('live');
    });

    it('stops when the archive does not contain the site directory', function () {
        // Extraction succeeds but produces nothing we recognise. Swapping an
        // empty directory over a working site is the failure this prevents.
        Process::fake(function ($process) {
            $command = $process->command;
            $args = $command[0] === 'sudo' ? array_slice($command, 2) : $command;

            if (($args[0] ?? '') === 'tar' && ($args[1] ?? '') === '-czf') {
                file_put_contents($args[2], str_repeat('s', 4096));
            }

            return Process::result(exitCode: 0);
        });

        $restore = app(RestoreRunner::class)->run(restoreFor(storedBackup()));

        expect($restore->status)->toBe(RestoreStatus::Failed)
            ->and($restore->reason)->toBe('extract_archive')
            ->and(File::get($this->siteRoot.'/index.php'))->toContain('live');
    });
});

it('puts the site back when the swap fails half way', function () {
    // The move aside succeeds, the move into place does not — the window in
    // which the site does not exist at all.
    Process::fake(function ($process) {
        $command = $process->command;
        $args = $command[0] === 'sudo' ? array_slice($command, 2) : $command;

        if (($args[0] ?? '') === 'tar' && ($args[1] ?? '') === '-czf') {
            file_put_contents($args[2], str_repeat('s', 4096));
        }

        if (($args[0] ?? '') === 'tar' && ($args[1] ?? '') === '-xzf') {
            $site = $args[4].'/'.basename(test()->siteRoot);
            File::ensureDirectoryExists($site);
            File::put($site.'/index.php', '<?php echo "restored";');
        }

        if (($args[0] ?? '') === 'mv') {
            // Only the staged copy refuses to move into place. The move aside
            // and the rollback back must both work, or this would be testing
            // a filesystem where nothing can move rather than the one failure
            // that matters.
            if (str_contains((string) $args[1], '.restore-')) {
                return Process::result(errorOutput: 'permission denied', exitCode: 1);
            }

            @rename($args[1], $args[2]);
        }

        return Process::result(exitCode: 0);
    });

    $restore = app(RestoreRunner::class)->run(restoreFor(storedBackup()));

    expect($restore->status)->toBe(RestoreStatus::Failed)
        ->and($restore->reason)->toBe('swap_files')
        // Back where it started, with the original content.
        ->and(File::get($this->siteRoot.'/index.php'))->toContain('live');
});

it('leaves no staging directory beside the site, whatever happened', function () {
    fakeSafetyBackupTar();

    app(RestoreRunner::class)->run(restoreFor(storedBackup()));

    $leftovers = array_filter(
        File::directories($this->home),
        fn (string $path) => str_contains(basename($path), '.restore-'),
    );

    // A half-unpacked copy of a site sitting next to the live one is both
    // confusing and expensive.
    expect($leftovers)->toBe([]);
});
