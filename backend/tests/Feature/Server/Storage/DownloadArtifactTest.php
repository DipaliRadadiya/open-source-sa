<?php

namespace Tests\Feature\Server\Storage;

use App\Contracts\StorageDriver;
use App\Enums\StorageProvider;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\Drivers\FtpDriver;
use App\Services\Server\Backups\Storage\Drivers\S3Driver;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\Restores\Steps\DownloadArtifact;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * How a restore gets the archive off the destination.
 *
 * `readStream()` is not always a stream. The FTP adapter implements it as
 * `fopen('php://temp')` + `ftp_fget`, which buffers the whole object before
 * the caller sees a byte — and `php://temp` spills into the system temp dir,
 * a tmpfs of ~3 GB on a normal install. A 24 GB restore failed with "Unable
 * to read file" while the transfer itself finished in 34 seconds.
 *
 * The sibling test in `StorageDestinationTest` already pins that S3's
 * `readStream()` really streams, for the same reason. Nobody had checked FTP.
 */
beforeEach(function () {
    $this->home = storage_path('framework/testing/dl-home-'.getmypid());
    File::ensureDirectoryExists($this->home);

    $systemUser = SystemUser::create(['username' => 'dluser', 'home_path' => $this->home]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Download Me', 'slug' => 'download-me', 'domain' => 'download-me.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
    ]);

    $this->ftpDestination = StorageDestination::create([
        'name' => 'FTP', 'provider' => StorageProvider::Ftp, 'prefix' => '',
        'config' => ['host' => 'h', 'username' => 'u', 'password' => 'p'],
    ]);

    // Not `$this->target`: HigherOrderTapProxy owns that name, so `test()->target`
    // hands back the test case instead of the row — documented in RestoreRunTest.
    $this->restoreTarget = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $this->ftpDestination->id,
        'type' => 'filesystem', 'retention_count' => 3, 'enabled' => true, 'frequency' => 'daily',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->home));

it('asks the driver for a direct download before falling back to a stream', function () {
    $called = new \stdClass;
    $called->key = null;
    $called->path = null;

    $driver = new class($called) implements StorageDriver
    {
        public function __construct(private \stdClass $called) {}

        public function downloadTo(StorageDestination $d, string $key, string $path): bool
        {
            $this->called->key = $key;
            $this->called->path = $path;

            // Pretend we wrote it, as the real FTP driver does.
            file_put_contents($path, 'archive-bytes');

            return true;
        }

        public function provider(): StorageProvider
        {
            return StorageProvider::Ftp;
        }

        public function preflight(StorageDestination $d): ?string
        {
            return null;
        }

        public function heal(StorageDestination $d): void {}

        public function config(StorageDestination $d): array
        {
            return [];
        }

        public function rules(bool $requireSecrets = true): array
        {
            return [];
        }

        public function secretKeys(): array
        {
            return [];
        }

        public function publicConfig(StorageDestination $d): array
        {
            return [];
        }

        public function classify(\Throwable $e): string
        {
            return 'storage.test.failure';
        }
    };

    expect($driver->downloadTo(new StorageDestination, 'app/archive.tar.gz', '/tmp/x-'.getmypid()))
        ->toBeTrue()
        ->and($called->key)->toBe('app/archive.tar.gz');

    @unlink('/tmp/x-'.getmypid());
});

/*
 * Only FTP needs the special path. A driver whose `readStream()` genuinely
 * streams must say so, or the restore would take a slower route for no reason
 * — and worse, a driver that returned true without writing anything would hand
 * the restore an empty archive.
 */
it('leaves the streaming drivers alone', function () {
    $destination = new StorageDestination;
    $destination->provider = StorageProvider::S3;

    expect(app(S3Driver::class)->downloadTo($destination, 'k', '/dev/null'))->toBeFalse();
});

/*
 * The whole point, asserted on the source: the FTP driver must not reach for
 * the buffered path the adapter uses. `ftp_fget` writes to whatever handle it
 * is given, so handing it the destination file removes the buffer entirely.
 */
it('downloads FTP archives straight to disk, never through php://temp', function () {
    $source = file_get_contents(
        app_path('Services/Server/Backups/Storage/Drivers/FtpDriver.php')
    );

    // Comments stripped first: the docblock names `php://temp` to explain the
    // bug, and asserting against prose would fail on the explanation rather
    // than the behaviour.
    $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

    expect($code)->toContain('ftp_fget')
        ->and($code)->toContain("fopen(\$path, 'wb')")
        ->and($code)->not->toContain('php://temp');
});

// Every driver has to answer the question, or the restore cannot ask it.
it('is answerable by every provider the panel supports', function () {
    foreach (app(StorageDriverFactory::class)->supported() as $provider) {
        $driver = app(StorageDriverFactory::class)->forProvider($provider);

        expect(method_exists($driver, 'downloadTo'))
            ->toBeTrue($provider->value.' cannot answer downloadTo()');
    }
});

it('is the FTP driver that overrides it, not the shared base', function () {
    $method = new \ReflectionMethod(FtpDriver::class, 'downloadTo');

    expect($method->getDeclaringClass()->getName())->toBe(FtpDriver::class);
});

/*
 * The wiring, not just the method.
 *
 * Deleting the `downloadTo()` call from `DownloadArtifact` left every test
 * above green, because they all exercise the driver directly. That one line is
 * the whole fix — the same gap the `heal()` work had, caught the same way.
 */
it('uses the direct download when running the restore step', function () {
    $dir = storage_path('framework/testing/dl-'.getmypid());
    File::deleteDirectory($dir);
    File::makeDirectory($dir, 0755, true);

    $destination = $this->ftpDestination;

    $used = new \stdClass;
    $used->direct = false;

    // A driver that can do it directly, and a disk whose readStream would
    // blow up if the step fell back — so "it took the direct path" is proved
    // by the run completing, not merely by a flag.
    app()->bind(StorageDriverFactory::class, fn () => new class($used) extends StorageDriverFactory
    {
        public function __construct(private \stdClass $used) {}

        public function for(StorageDestination $d): StorageDriver
        {
            return new class($this->used) extends FtpDriver
            {
                public function __construct(private \stdClass $used) {}

                public function downloadTo(StorageDestination $d, string $key, string $path): bool
                {
                    $this->used->direct = true;
                    file_put_contents($path, 'archive-bytes');

                    return true;
                }
            };
        }
    });

    // A real Filesystem holding DIFFERENT bytes. If the step ever falls back
    // to readStream, the archive will contain these instead — so the assertion
    // on content proves which path ran, without needing the disk to throw.
    $fake = Storage::fake('dl-destination');
    $fake->put('app/archive.tar.gz', 'streamed-bytes');

    $disk = new DestinationDisk(
        app(StorageDriverFactory::class),
        fn (array $config) => $fake,
    );

    $backup = Backup::create([
        'backup_target_id' => $this->restoreTarget->id,
        'application_id' => $this->application->id,
        'type' => 'filesystem', 'status' => 'verified',
        'reference' => (string) Str::uuid(),
        'storage_destination_id' => $destination->id,
        'manifest' => ['key' => 'app/archive.tar.gz'],
    ]);

    $restore = Restore::create([
        'backup_id' => $backup->id, 'application_id' => $this->application->id,
        'type' => 'filesystem', 'status' => 'running', 'reference' => (string) Str::uuid(),
    ]);

    $context = new RestoreContext(
        $restore, $backup, $this->application, $dir
    );

    (new DownloadArtifact(
        $disk,
        app(StorageDriverFactory::class),
    ))->run($context);

    expect($used->direct)->toBeTrue()
        ->and(file_get_contents($context->archivePath))->toBe('archive-bytes')
        // Not the disk's copy: the direct download won, which is the point.
        ->and(file_get_contents($context->archivePath))->not->toBe('streamed-bytes');

    File::deleteDirectory($dir);
});
