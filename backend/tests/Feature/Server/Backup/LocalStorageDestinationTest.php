<?php

use App\Enums\BackupStatus;
use App\Exceptions\NotEnoughBackupSpace;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\LocalSpaceGuard;
use App\Services\Server\Backups\Storage\Drivers\LocalDriver;
use App\Services\Server\Backups\Storage\LocalBackupFolder;
use App\Services\Server\ServerOps;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process as RealProcess;

/*
 * #14 (support list, 2026-10-27): backups kept in a folder on this server.
 * The folder is the panel's and private, made only when the user saves, and
 * a backup that would leave the disk too full is refused — before it starts
 * (estimate) and before it is stored (real size).
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    // Same disk as the backup working folder, so a store is a real move.
    $this->base = storage_path('framework/testing/local-backup-'.getmypid());
    File::deleteDirectory($this->base);
    File::ensureDirectoryExists("{$this->base}/backups");
    File::ensureDirectoryExists("{$this->base}/mnt/disk2");
    config([
        'server.backups.local.allowed_roots' => ["{$this->base}/backups", "{$this->base}/mnt"],
        'server.backups.local.min_free_percent' => 0,
        'server.backups.local.min_free_bytes' => 0,
    ]);
    $this->panel = app(HomeDirectoryAccess::class)->panelAccount();

    // Commands really run (as this user — tests never elevate), except where a
    // test says another account owns a path: the one thing it cannot arrange.
    $this->owners = [];
    $this->ran = new ArrayObject;
    Process::fake(function ($process) {
        $command = (array) $process->command;
        $this->ran[] = $command;

        if ($command[0] === 'stat' && isset($this->owners[end($command)])) {
            return Process::result(output: $this->owners[end($command)].'|directory');
        }

        if ($command[0] === 'chown' && isset($this->owners[end($command)])) {
            return Process::result();
        }

        if ($command[0] === 'tar') {
            file_put_contents(tarArchivePath($command), str_repeat('x', 4096));

            return Process::result();
        }

        $real = new RealProcess($command);
        $real->run();

        return Process::result(output: $real->getOutput(), errorOutput: $real->getErrorOutput(), exitCode: $real->getExitCode());
    });
});

afterEach(fn () => File::deleteDirectory($this->base));

function lbSave(string $path, string $name = 'On this server'): TestResponse
{
    return test()->postJson('/api/integrations/storage/destinations', [
        'name' => $name, 'provider' => 'local', 'config' => ['path' => $path],
    ]);
}

function lbDestination(string $path): StorageDestination
{
    File::ensureDirectoryExists($path, 0700);

    return StorageDestination::create(['name' => 'Local '.uniqid(), 'provider' => 'local', 'config' => ['path' => $path]]);
}

function lbMode(string $path): string
{
    clearstatcache();

    return substr(sprintf('%o', fileperms($path)), -4);
}

describe('saving a destination: the folder', function () {

    it('creates a missing folder, private, and says so', function () {
        $path = "{$this->base}/mnt/disk2/backups";

        lbSave($path)
            ->assertCreated()
            ->assertJsonPath('folder.status', 'created')
            ->assertJsonPath('folder.message', "Folder created: {$path}")
            ->assertJsonPath('storage_destination.config.path', $path)
            ->assertJsonPath('storage_destination.config.same_server', true)
            ->assertJsonPath('storage_destination.has_credentials', true);

        expect(is_dir($path))->toBeTrue()->and(lbMode($path))->toBe('0700');
    });

    it('uses a folder that is already the panel\'s, and makes it private', function () {
        $path = "{$this->base}/backups/panel";
        File::ensureDirectoryExists($path, 0755);

        lbSave($path.'/')
            ->assertCreated()
            ->assertJsonPath('folder.status', 'existing')
            // Stored without the trailing slash, so it compares as one folder.
            ->assertJsonPath('storage_destination.config.path', $path);

        expect(lbMode($path))->toBe('0700');
    });

    it('takes over an empty folder another account made, and hands it to the panel', function () {
        $path = "{$this->base}/mnt/disk2/empty";
        File::ensureDirectoryExists($path);
        $this->owners[$path] = 'root';

        lbSave($path)->assertCreated()->assertJsonPath('folder.status', 'existing');

        expect(collect($this->ran))->toContain(['chown', $this->panel, $path]);
    });

    it('refuses someone else\'s folder that has files in it, and changes nothing', function () {
        $path = "{$this->base}/mnt/disk2/theirs";
        File::ensureDirectoryExists($path);
        File::put("{$path}/their-file", 'x');
        $this->owners[$path] = 'siteuser';

        lbSave($path)
            ->assertStatus(422)
            ->assertJsonFragment(['config.path' => ['This folder belongs to another account and is not empty. Choose an empty or new folder.']]);

        expect(collect($this->ran)->first(fn ($c) => in_array($c[0], ['chown', 'chmod', 'mkdir'], true)))->toBeNull()
            ->and(StorageDestination::count())->toBe(0);
    });

    it('does not invent a missing disk\'s mount point', function () {
        $path = "{$this->base}/mnt/disk3/backups";

        lbSave($path)
            ->assertStatus(422)
            ->assertJsonFragment(['config.path' => ["The folder {$this->base}/mnt/disk3 does not exist. If it is an extra disk, check that it is mounted."]]);

        expect(is_dir("{$this->base}/mnt/disk3"))->toBeFalse();
    });

    it('refuses a folder outside the allowed roots, or a root itself', function (string $where) {
        $path = str_replace('{base}', $this->base, $where);

        lbSave($path)->assertStatus(422)->assertJsonValidationErrors('config.path');

        expect(StorageDestination::count())->toBe(0);
    })->with([
        'system folder' => ['/etc/panel-backups'],
        'site homes' => ['/home/backups'],
        'the root itself' => ['{base}/mnt'],
        'relative' => ['backups/panel'],
        'walks out' => ['{base}/mnt/../../etc'],
    ]);

    it('refuses a folder reached through a link', function () {
        symlink('/etc', "{$this->base}/mnt/sneaky");

        lbSave("{$this->base}/mnt/sneaky/backups")
            ->assertStatus(422)
            ->assertJsonFragment(['config.path' => ['The folder, or a folder on its path, is a link. Choose a real folder.']]);
    });

    it('refuses a folder another destination already uses', function () {
        $path = "{$this->base}/backups/shared";
        lbSave($path, 'First')->assertCreated();

        lbSave($path.'/', 'Second')
            ->assertStatus(422)
            ->assertJsonFragment(['config.path' => ['Another destination already uses this folder.']]);
    });

    it('refuses a user who can only view storage', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'storage');
        $this->actingAs($viewer);

        lbSave("{$this->base}/backups/x")->assertForbidden();

        expect(is_dir("{$this->base}/backups/x"))->toBeFalse();
    });
});

describe('testing a destination', function () {

    it('reports the free space when the folder is ready', function () {
        $destination = lbDestination("{$this->base}/backups/ready");

        $this->postJson("/api/integrations/storage/destinations/{$destination->id}/test")
            ->assertOk()
            ->assertJsonPath('test.success', true)
            ->assertJsonPath('test.message', fn (string $m) => str_starts_with($m, 'The folder is ready.') && str_contains($m, 'free of'));
    });

    it('reports a folder that has gone, and does not recreate it', function () {
        $destination = lbDestination("{$this->base}/mnt/disk2/gone");
        File::deleteDirectory("{$this->base}/mnt/disk2/gone");

        $this->postJson("/api/integrations/storage/destinations/{$destination->id}/test")
            ->assertOk()
            ->assertJsonPath('test.success', false)
            ->assertJsonPath('test.error_class', 'local_missing');

        expect(is_dir("{$this->base}/mnt/disk2/gone"))->toBeFalse();
    });
});

describe('free space', function () {

    it('keeps 10% of the disk or 2 GB free, whichever is more', function () {
        config(['server.backups.local.min_free_percent' => 10, 'server.backups.local.min_free_bytes' => 2 * 1024 ** 3]);
        $guard = app(LocalSpaceGuard::class);

        expect($guard->reserve(100 * 1024 ** 3))->toBe(10 * 1024 ** 3)
            ->and($guard->reserve(10 * 1024 ** 3))->toBe(2 * 1024 ** 3);
    });

    it('refuses exactly at the edge, not one byte past it', function () {
        $path = "{$this->base}/backups/edge";
        File::ensureDirectoryExists($path);
        $free = (int) disk_free_space($path);
        config(['server.backups.local.min_free_percent' => 0, 'server.backups.local.min_free_bytes' => 1000]);
        $guard = app(LocalSpaceGuard::class);

        // Free space moves while the suite runs; leave a margin that it
        // cannot cross in between.
        $guard->ensureRoomFor($path, $free - 1000 - 50 * 1024 ** 2);

        expect(fn () => $guard->ensureRoomFor($path, $free))->toThrow(NotEnoughBackupSpace::class);
    });
});

describe('storing an archive', function () {

    it('moves it in on the same disk — no copy — and makes it the panel\'s, private', function () {
        $destination = lbDestination("{$this->base}/backups/move");
        $archive = storage_path('app/backups/lb-'.getmypid().'.tar.gz');
        File::ensureDirectoryExists(dirname($archive));
        file_put_contents($archive, str_repeat('a', 1234));
        $progress = null;

        $stored = app(LocalDriver::class)->uploadFrom($destination, 'shop.test/2026-10-27-1200-abc.tar.gz', $archive, function ($b) use (&$progress) {
            $progress = $b;
        });

        $target = "{$this->base}/backups/move/shop.test/2026-10-27-1200-abc.tar.gz";
        expect($stored)->toBeTrue()
            ->and(file_exists($archive))->toBeFalse()
            ->and(filesize($target))->toBe(1234)
            ->and(lbMode($target))->toBe('0600')
            ->and(lbMode(dirname($target)))->toBe('0700')
            ->and($progress)->toBe(1234)
            ->and(collect($this->ran))->toContain(['chown', $this->panel, $target]);
    });

    it('copies it to another disk, and never leaves a half-written file', function () {
        $destination = lbDestination("{$this->base}/backups/copy");
        $archive = storage_path('app/backups/lb-copy-'.getmypid().'.tar.gz');
        File::ensureDirectoryExists(dirname($archive));
        file_put_contents($archive, str_repeat('b', 999));

        $folder = Mockery::mock(LocalBackupFolder::class, [app(ServerOps::class), app(HomeDirectoryAccess::class)])->makePartial();
        $folder->shouldReceive('sameDisk')->andReturnFalse();
        app()->instance(LocalBackupFolder::class, $folder);

        app(LocalDriver::class)->uploadFrom($destination, 'a/b.tar.gz', $archive);

        expect(filesize("{$this->base}/backups/copy/a/b.tar.gz"))->toBe(999)
            ->and(file_exists("{$this->base}/backups/copy/a/b.tar.gz.part"))->toBeFalse()
            // The working copy stays for the runner to clean up, as for any upload.
            ->and(file_exists($archive))->toBeTrue();

        @unlink($archive);
    });

    it('refuses an archive that would leave the disk too full, and stores nothing', function () {
        $destination = lbDestination("{$this->base}/backups/full");
        config(['server.backups.local.min_free_bytes' => PHP_INT_MAX >> 2]);
        $archive = storage_path('app/backups/lb-full-'.getmypid().'.tar.gz');
        File::ensureDirectoryExists(dirname($archive));
        file_put_contents($archive, 'c');

        expect(fn () => app(LocalDriver::class)->uploadFrom($destination, 'x.tar.gz', $archive))->toThrow(NotEnoughBackupSpace::class)
            ->and(File::allFiles("{$this->base}/backups/full"))->toBe([])
            ->and(file_exists($archive))->toBeTrue();

        @unlink($archive);
    });

    it('only hands out files inside the folder', function () {
        $destination = lbDestination("{$this->base}/backups/inside");
        file_put_contents("{$this->base}/backups/outside.tar.gz", 'secret');
        file_put_contents("{$this->base}/backups/inside/ok.tar.gz", 'ok');
        $driver = app(LocalDriver::class);

        expect($driver->file($destination, 'ok.tar.gz'))->toBe(realpath("{$this->base}/backups/inside/ok.tar.gz"))
            ->and($driver->file($destination, '../outside.tar.gz'))->toBeNull()
            ->and($driver->file($destination, 'missing.tar.gz'))->toBeNull();
    });
});

describe('a whole backup to this server', function () {

    beforeEach(function () {
        $this->home = "{$this->base}/home";
        File::ensureDirectoryExists("{$this->home}/site/public_html");
        $user = SystemUser::create(['username' => 'lbsite', 'home_path' => $this->home]);
        $this->site = Application::forceCreate([
            'system_user_id' => $user->id, 'name' => 'Site', 'slug' => 'site', 'domain' => 'site.test',
            'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
        ]);
        $this->destination = lbDestination("{$this->base}/backups/whole");
        $this->target = BackupTarget::create([
            'application_id' => $this->site->id, 'storage_destination_id' => $this->destination->id,
            'type' => 'filesystem', 'retention_count' => 2, 'enabled' => true, 'frequency' => 'daily',
        ]);
    });

    it('stores, verifies, downloads, restores by link and keeps only the newest', function () {
        $runner = app(BackupRunner::class);
        $first = $runner->run($this->target);

        expect($first->status)->toBe(BackupStatus::Verified)
            ->and(is_file("{$this->base}/backups/whole/".$first->manifest['key']))->toBeTrue();

        // Download: a signed panel link that reads the file where it is.
        $url = $this->getJson("/api/backups/{$first->id}/download")->assertOk()->json('download.url');
        expect($url)->toContain('/local-file?')
            ->and($this->get($url)->assertOk()->streamedContent())->toBe(str_repeat('x', 4096));

        // A changed key no longer matches its signature.
        $this->get(str_replace('key=', 'key=..%2F', $url))->assertForbidden();

        // Restore's fetch: a hard link, not a second copy.
        $into = storage_path('app/backups/lb-restore-'.getmypid());
        expect(app(LocalDriver::class)->downloadTo($this->destination, $first->manifest['key'], $into))->toBeTrue()
            ->and(fileinode($into))->toBe(fileinode("{$this->base}/backups/whole/".$first->manifest['key']));
        @unlink($into);

        $this->travel(1)->minutes();
        $runner->run($this->target);
        $this->travel(1)->minutes();
        $third = $runner->run($this->target);

        expect(Backup::where('status', BackupStatus::Verified)->count())->toBe(2)
            ->and(is_file("{$this->base}/backups/whole/".$first->manifest['key']))->toBeFalse()
            ->and(is_file("{$this->base}/backups/whole/".$third->manifest['key']))->toBeTrue();
    });

    it('refuses before writing anything when the last backup would not fit again', function () {
        app(BackupRunner::class)->run($this->target);
        config(['server.backups.local.min_free_bytes' => (int) disk_free_space("{$this->base}/backups/whole") - 1024]);
        $this->ran->exchangeArray([]);

        $failed = app(BackupRunner::class)->run($this->target);

        expect($failed->status)->toBe(BackupStatus::Failed)
            ->and($failed->reason)->toBe('not_enough_space')
            ->and($failed->error_class)->toBe('local_no_space')
            // Refused at the first step: nothing was archived.
            ->and(collect($this->ran)->first(fn ($c) => $c[0] === 'tar'))->toBeNull();
    });

    it('fails before archiving when the folder has gone, and does not recreate it', function () {
        File::deleteDirectory("{$this->base}/backups/whole");

        $failed = app(BackupRunner::class)->run($this->target);

        expect($failed->status)->toBe(BackupStatus::Failed)
            ->and($failed->reason)->toBe('check_local_space')
            ->and($failed->error_class)->toBe('local_missing')
            ->and(is_dir("{$this->base}/backups/whole"))->toBeFalse()
            ->and(collect($this->ran)->first(fn ($c) => $c[0] === 'tar'))->toBeNull();
    });
});

it('is never touched by the disk cleaner', function () {
    $roots = (array) config('server.backups.local.allowed_roots');
    $config = json_encode(config('server.disk_cleaner'));

    foreach (['/var/backups', '/mnt', '/media', ...$roots] as $root) {
        expect($config)->not->toContain(str_replace('/', '\/', $root));
    }
});
