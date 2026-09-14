<?php

use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Services\Server\Backups\BackupRoot;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\Restores\Steps\ExtractArchive;
use App\Services\Server\Restores\Steps\SwapFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * A backup is of the application, not of the directory the web server happens
 * to hand out. The two are the same for most site types and differ for exactly
 * the ones where it matters most — a Laravel or Craft site serves `public`, so
 * archiving the served directory captured the public folder and none of the
 * application, and restored cleanly while doing it.
 *
 * The archive's top-level entry changed with that, so the other half of this
 * file is the compatibility rule: an archive written before the change still
 * restores to the directory it was made from.
 */

beforeEach(function () {
    $this->fakeDisk = Storage::fake('destination');

    // The disk is built per-destination and never registered globally, so the
    // fake has to be injected the same way production builds the real one.
    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
        app(StorageDriverFactory::class),
        fn (array $config) => $this->fakeDisk,
    ));

    $this->home = storage_path('framework/testing/home-'.uniqid());

    $systemUser = SystemUser::create([
        'username' => 'rootuser',
        'home_path' => $this->home,
    ]);

    $this->destination = StorageDestination::create([
        'name' => 'Backups',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'backups', 'access_key' => 'key', 'secret_key' => 'secret'],
    ]);

    $this->systemUser = $systemUser;
});

afterEach(function () {
    File::deleteDirectory($this->home);
});

/**
 * A site served from a subdirectory of itself — the shape this whole file is
 * about. `public_html` holds the application; `public_html/public` is all the
 * web server ever sees.
 */
function servedFromSubdirectory(string $webRoot = 'public'): Application
{
    $application = Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => 'Laravel Site',
        'slug' => 'laravel-site',
        'domain' => 'laravel.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => $webRoot,
    ]);

    File::ensureDirectoryExists($application->publicHtmlPath().'/'.$webRoot);
    File::put($application->publicHtmlPath().'/.env', 'APP_KEY=base64:x');
    File::put($application->publicHtmlPath().'/'.$webRoot.'/index.php', '<?php');

    return $application;
}

/**
 * An ArrayObject, not an array: a `use (&$ref)` closure returned from a helper
 * hands the caller the value as it was when the helper returned — empty — and
 * every assertion then reads nothing and says so in a way that looks like the
 * code under test.
 */
function recordedTarCommands(): ArrayObject
{
    $commands = new ArrayObject;

    Process::fake(function ($process) use ($commands) {
        $commands[] = $process->command;

        // Everything here runs through ServerOps, so the command that arrives
        // is `sudo -n tar …`. Read arguments by the flag they follow rather
        // than by position — a fixed index silently matches nothing the day a
        // prefix is added, and a fake that writes the archive to the wrong
        // path fails the step under test for a reason that is not the step.
        $archive = argumentAfter($process->command, '-czf');

        if ($archive !== null) {
            file_put_contents($archive, str_repeat('x', 2048));
        }

        return Process::result(exitCode: 0);
    });

    return $commands;
}

/** The argument following `$flag`, or null when the command has no such flag. */
function argumentAfter(array $command, string $flag): ?string
{
    $index = array_search($flag, $command, true);

    return $index === false ? null : ($command[$index + 1] ?? null);
}

/** The first recorded command that runs `$binary`, whatever precedes it. */
function commandRunning(ArrayObject $commands, string $binary): ?array
{
    foreach ($commands as $command) {
        if (in_array($binary, $command, true)) {
            return $command;
        }
    }

    return null;
}

function targetFor(Application $application): BackupTarget
{
    return BackupTarget::create([
        'application_id' => $application->id,
        'storage_destination_id' => test()->destination->id,
        'type' => 'filesystem',
        'retention_count' => 3,
        'enabled' => true,
        'frequency' => 'daily',
    ]);
}

it('archives the application, not the directory the web server serves', function () {
    $application = servedFromSubdirectory();
    $commands = recordedTarCommands();

    app(BackupRunner::class)->run(targetFor($application));

    $tar = commandRunning($commands, 'tar');

    // `-C <parent> <entry>`: the entry is the site's own directory, and the
    // parent is what it sits in. Archiving `public` under `public_html` would
    // have produced a tarball with no application in it at all.
    expect($tar)->toContain($application->rootPath())
        ->and($tar)->toContain('public_html')
        ->and($tar)->not->toContain($application->publicHtmlPath());
});

it('records which directory the archive was made from', function () {
    $application = servedFromSubdirectory();
    recordedTarCommands();

    $backup = app(BackupRunner::class)->run(targetFor($application));

    // Without this on the row, a restore has no way to tell an archive of the
    // application from an archive of the served directory — they differ only
    // in the name of an entry inside them.
    expect($backup->manifest['root_kind'])->toBe(BackupRoot::APPLICATION);
});

it('leaves a site served from its own root exactly where it was', function () {
    $application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Plain Site',
        'slug' => 'plain-site',
        'domain' => 'plain.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    File::ensureDirectoryExists($application->publicHtmlPath());
    File::put($application->publicHtmlPath().'/index.php', '<?php');

    $commands = recordedTarCommands();

    app(BackupRunner::class)->run(targetFor($application));

    $tar = commandRunning($commands, 'tar');

    // The ordinary case — where the document root and the application are the
    // same directory — must not move at all.
    expect($tar)->toContain($application->rootPath())
        ->and($tar)->toContain('public_html');
});

it('resolves an archive with no recorded kind to the served directory', function () {
    $application = servedFromSubdirectory();

    // Every backup taken before this feature existed. The absent key is the
    // answer, not a missing one.
    expect(app(BackupRoot::class)->forRestore($application, ['key' => 'backups/x/y.tar.gz']))
        ->toBe($application->documentRoot())
        ->and(app(BackupRoot::class)->forRestore($application, null))
        ->toBe($application->documentRoot());
});

it('resolves an archive of the application to the application', function () {
    $application = servedFromSubdirectory();

    expect(app(BackupRoot::class)->forRestore($application, ['root_kind' => BackupRoot::APPLICATION]))
        ->toBe($application->publicHtmlPath());
});

it('unpacks an old archive beside the directory it was made from', function () {
    $application = servedFromSubdirectory();

    $backup = Backup::create([
        'backup_target_id' => targetFor($application)->id,
        'application_id' => $application->id,
        'type' => 'filesystem',
        'status' => BackupStatus::Verified,
        // No `root_kind`: this archive predates the change.
        'manifest' => ['key' => 'backups/laravel.example.com/2026-01-01/old.tar.gz'],
    ]);

    $commands = new ArrayObject;

    Process::fake(function ($process) use ($commands) {
        $commands[] = $process->command;

        // Play the part of tar: an old archive holds `public`, because that is
        // what the document root was called when it was written.
        if (argumentAfter($process->command, '-xzf') !== null) {
            $staging = argumentAfter($process->command, '-C');

            if ($staging !== null) {
                File::ensureDirectoryExists($staging.'/public');
            }
        }

        if (in_array('mkdir', $process->command, true)) {
            File::ensureDirectoryExists(argumentAfter($process->command, '-p') ?? '');
        }

        return Process::result(exitCode: 0);
    });

    $archive = $this->home.'/old.tar.gz';
    File::ensureDirectoryExists($this->home);
    File::put($archive, 'x');

    $restore = Restore::create([
        'backup_id' => $backup->id,
        'application_id' => $application->id,
        'type' => 'filesystem',
        'status' => 'running',
    ]);

    $context = new RestoreContext($restore, $backup, $application, $this->home);
    $context->archivePath = $archive;

    app(ExtractArchive::class)->run($context);

    // Beside `public_html/public`, not beside `public_html`. Get this wrong and
    // the step throws "the archive does not contain the site directory" on a
    // perfectly good backup.
    expect($context->stagingDirectory)->toBe($application->publicHtmlPath().'/.restore-'.$restore->id);
});

it('swaps an archive of the application over the application', function () {
    $application = servedFromSubdirectory();

    $backup = Backup::create([
        'backup_target_id' => targetFor($application)->id,
        'application_id' => $application->id,
        'type' => 'filesystem',
        'status' => BackupStatus::Verified,
        'manifest' => ['key' => 'k', 'root_kind' => BackupRoot::APPLICATION],
    ]);

    $moves = new ArrayObject;

    Process::fake(function ($process) use ($moves) {
        $mv = array_search('mv', $process->command, true);

        if ($mv !== false) {
            $moves[] = [$process->command[$mv + 1], $process->command[$mv + 2]];
        }

        return Process::result(exitCode: 0);
    });

    $staging = $this->home.'/.restore-staging';
    File::ensureDirectoryExists($staging.'/public_html');

    $restore = Restore::create([
        'backup_id' => $backup->id,
        'application_id' => $application->id,
        'type' => 'filesystem',
        'status' => 'running',
    ]);

    $context = new RestoreContext($restore, $backup, $application, $this->home);
    $context->stagingDirectory = $staging;

    app(SwapFiles::class)->run($context);

    // The live application moves aside and the restored one takes its place —
    // both at `public_html`, never at `public_html/public`.
    expect($moves->getArrayCopy())->toContain([$application->publicHtmlPath(), $application->rootPath().'/.rollback-'.$restore->id])
        ->and($moves->getArrayCopy())->toContain([$staging.'/public_html', $application->publicHtmlPath()]);
});
