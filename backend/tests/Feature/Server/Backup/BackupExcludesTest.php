<?php

use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\BackupRoot;
use App\Services\Server\Backups\Steps\ArchiveFiles;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\Restores\Steps\SwapFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Bugs #77 and #78, with real tar, find and cp on real directories -- the
 * failure was in what tar does with the patterns, which no fake can show.
 *
 * #77: tar applies every --exclude to every member, and the database dumps
 * ride in the same archive as the site. Excluding "*.sql" (meant for stray
 * dumps in the web root) dropped the backup's own database dump, and the
 * "Files and database" backup finished as complete with no database.
 *
 * #78: a restore swaps in the archive, which lacks what was excluded, so an
 * excluded uploads folder was deleted from the site for good.
 */
beforeEach(function () {
    config(['server.privilege.sudo' => false]);

    $this->tmp = sys_get_temp_dir().'/sv-backup-excludes-'.Str::random(8);
    $home = "{$this->tmp}/home/owner";
    File::makeDirectory("{$home}/blog/public_html/wp-content/uploads/2026", 0755, true);
    File::makeDirectory("{$home}/blog/public_html/node_modules/pkg", 0755, true);
    File::put("{$home}/blog/public_html/index.php", '<?php');
    // A bare name excluded at any depth, not only at the top.
    File::makeDirectory("{$home}/blog/public_html/wp-content/cache/page", 0755, true);
    File::put("{$home}/blog/public_html/wp-content/cache/page/x.html", 'cached');
    File::put("{$home}/blog/public_html/old-export.sql", 'stray');
    File::put("{$home}/blog/public_html/wp-content/uploads/2026/photo.jpg", 'photo');
    File::put("{$home}/blog/public_html/node_modules/pkg/index.js", 'js');

    $user = SystemUser::create(['username' => 'owner', 'home_path' => $home]);
    $this->application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
    ]);

    $destination = StorageDestination::create([
        'name' => 'dest-'.Str::random(6), 'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
    ]);
    $this->target = BackupTarget::create([
        'application_id' => $this->application->id, 'storage_destination_id' => $destination->id,
        'type' => 'full', 'retention_count' => 2, 'frequency' => 'manual',
        'file_excludes' => ['*.sql', 'wp-content/uploads', '/node_modules', 'cache'],
    ]);
    $this->backup = Backup::create([
        'backup_target_id' => $this->target->id, 'application_id' => $this->application->id,
        'type' => 'full', 'status' => BackupStatus::Running,
    ]);
});

afterEach(fn () => File::deleteDirectory($this->tmp));

function archiveListing(string $archive): array
{
    exec('tar -tzf '.escapeshellarg($archive), $lines, $code);
    expect($code)->toBe(0);

    return array_map(fn ($l) => rtrim($l, '/'), $lines);
}

it('keeps the database dump however the site files are excluded', function () {
    $work = "{$this->tmp}/work";
    File::makeDirectory($work);
    File::put("{$work}/db-blog.sql", 'CREATE TABLE wp_posts;');

    $context = new BackupContext($this->backup, $this->target->fresh(), $work);
    $context->localArtifacts[] = "{$work}/db-blog.sql";

    app(ArchiveFiles::class)->run($context);

    $listing = archiveListing($context->archivePath);

    // The archive holds the directory BackupRoot names (public_html here),
    // with the dumps beside it at the top.
    expect($listing)->toContain('db-blog.sql')
        ->toContain('public_html/index.php')
        ->not->toContain('public_html/old-export.sql')
        ->not->toContain('public_html/wp-content/uploads')
        ->not->toContain('public_html/node_modules')
        ->not->toContain('public_html/wp-content/cache')
        // Recorded for the restore; a leading "/" means the same thing.
        ->and($context->manifest['file_excludes'])->toBe(['*.sql', 'wp-content/uploads', 'node_modules', 'cache']);
});

it('excludes nothing at all when the target has no patterns', function () {
    $this->target->update(['file_excludes' => []]);
    $work = "{$this->tmp}/work";
    File::makeDirectory($work);

    $context = new BackupContext($this->backup, $this->target->fresh(), $work);
    app(ArchiveFiles::class)->run($context);

    expect(archiveListing($context->archivePath))->toContain('public_html/old-export.sql', 'public_html/node_modules/pkg/index.js');
});

it('carries what the backup excluded over into the restored site', function () {
    $siteRoot = app(BackupRoot::class)->toArchive($this->application)['path'];
    $previous = dirname($siteRoot).'/.rollback-1';

    // The live site moved aside, and the archive's copy in its place --
    // without uploads or node_modules, which the backup excluded.
    File::moveDirectory($siteRoot, $previous);
    File::makeDirectory("{$siteRoot}/wp-content", 0755, true);
    File::put("{$siteRoot}/index.php", '<?php // from the backup');

    $this->backup->update(['manifest' => ['file_excludes' => ['*.sql', 'wp-content/uploads', 'node_modules', 'cache']]]);
    $restore = Restore::forceCreate(['backup_id' => $this->backup->id, 'application_id' => $this->application->id, 'status' => 'running', 'type' => 'full']);
    $context = new RestoreContext($restore, $this->backup->fresh(), $this->application, "{$this->tmp}/restore");

    $keep = new ReflectionMethod(SwapFiles::class, 'keepExcluded');
    $keep->invoke(app(SwapFiles::class), $context, $previous, $siteRoot);

    expect(File::get("{$siteRoot}/wp-content/uploads/2026/photo.jpg"))->toBe('photo')
        ->and(File::get("{$siteRoot}/node_modules/pkg/index.js"))->toBe('js')
        ->and(File::get("{$siteRoot}/old-export.sql"))->toBe('stray')
        ->and(File::get("{$siteRoot}/wp-content/cache/page/x.html"))->toBe('cached')
        // What the archive restored is not overwritten by the old copy.
        ->and(File::get("{$siteRoot}/index.php"))->toBe('<?php // from the backup')
        // The copy kept for Undo is still whole.
        ->and(File::exists("{$previous}/wp-content/uploads/2026/photo.jpg"))->toBeTrue();
});

it('falls back to the target patterns for a backup taken before they were recorded', function () {
    $siteRoot = app(BackupRoot::class)->toArchive($this->application)['path'];
    $previous = dirname($siteRoot).'/.rollback-2';
    File::moveDirectory($siteRoot, $previous);
    File::makeDirectory($siteRoot, 0755, true);

    $this->backup->update(['manifest' => ['root_kind' => 'application']]);
    $restore = Restore::forceCreate(['backup_id' => $this->backup->id, 'application_id' => $this->application->id, 'status' => 'running', 'type' => 'full']);
    $context = new RestoreContext($restore, $this->backup->fresh(), $this->application, "{$this->tmp}/restore");

    (new ReflectionMethod(SwapFiles::class, 'keepExcluded'))->invoke(app(SwapFiles::class), $context, $previous, $siteRoot);

    expect(File::exists("{$siteRoot}/wp-content/uploads/2026/photo.jpg"))->toBeTrue();
});
