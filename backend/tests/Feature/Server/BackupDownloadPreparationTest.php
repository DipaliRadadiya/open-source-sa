<?php

use App\Contracts\StorageDriver;
use App\Enums\BackupStatus;
use App\Jobs\FetchBackupForDownload;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupDownloadPreparation;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\Drivers\FtpDriver;
use App\Services\Server\Backups\Storage\Drivers\SftpDriver;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\UnableToReadFile;

/*
 * Download for backups on SFTP and FTP: prepare, then download.
 *
 * Neither provider has a link a browser can follow, so Download answered "not
 * available for this destination" — and the users of this panel are not going
 * to fetch an archive with FileZilla. The panel now copies the archive to its
 * own disk on the queue and serves that copy for an hour.
 */

const DL_ARCHIVE = "archive-bytes\x00\x01\x02 of a whole site";

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->downloadDir = storage_path('framework/testing/backup-downloads-'.getmypid());
    File::deleteDirectory($this->downloadDir);
    config(['server.backups.download_dir' => $this->downloadDir]);

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.example.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active',
    ]);

    $this->destination = StorageDestination::create([
        'name' => 'Offsite SFTP',
        'provider' => 'sftp',
        'config' => ['host' => 'backup.example.test', 'username' => 'u', 'password' => 'p', 'root' => '/backups'],
    ]);

    $this->backupTarget = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $this->destination->id,
        'type' => 'full', 'retention_count' => 7, 'frequency' => 'daily', 'enabled' => true,
    ]);

    $this->backup = Backup::create([
        'backup_target_id' => $this->backupTarget->id,
        'application_id' => $this->application->id,
        'type' => 'full',
        'status' => BackupStatus::Verified,
        'size_bytes' => strlen(DL_ARCHIVE),
        'manifest' => ['key' => 'shop/a1b2c3.tar.gz'],
    ]);

    // The archive on the "storage server": a real filesystem, so exists(),
    // fileSize() and readStream() answer the way the adapters do.
    $this->remote = Storage::fake('dl-remote');
    $this->remote->put('shop/a1b2c3.tar.gz', DL_ARCHIVE);

    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
        app(StorageDriverFactory::class),
        builder: fn (array $config) => $this->remote,
    ));

    /*
     * The driver: real SFTP/FTP classes (so `PreparesDownloads` is what it is
     * in production), with only the network part replaced. `$mode`:
     * `direct` writes the archive the way downloadTo() does, `fallback`
     * returns false so the copy comes from readStream(), `refused` throws
     * what phpseclib throws for a wrong password.
     */
    $this->driverMode = 'direct';
    $this->app->bind(StorageDriverFactory::class, fn () => new class($this) extends StorageDriverFactory
    {
        public function __construct(private $test) {}

        public function for(StorageDestination $destination): StorageDriver
        {
            $test = $this->test;
            $base = $destination->provider->value === 'ftp' ? FtpDriver::class : SftpDriver::class;

            if ($destination->provider->value === 's3') {
                return parent::for($destination);
            }

            return $base === FtpDriver::class
                ? new class($test) extends FtpDriver
                {
                    public function __construct(private $test) {}

                    public function downloadTo(StorageDestination $d, string $key, string $path): bool
                    {
                        return fakeDownloadTo($this->test, $path);
                    }
                }
            : new class($test) extends SftpDriver
            {
                public function __construct(private $test) {}

                public function downloadTo(StorageDestination $d, string $key, string $path): bool
                {
                    return fakeDownloadTo($this->test, $path);
                }
            };
        }
    });
});

afterEach(fn () => File::deleteDirectory($this->downloadDir));

function fakeDownloadTo($test, string $path): bool
{
    return match ($test->driverMode) {
        'direct' => file_put_contents($path, DL_ARCHIVE) !== false,
        'fallback' => false,
        'short' => file_put_contents($path, 'archive') !== false,
        'refused' => throw UnableToAuthenticate::withPassword('Permission denied'),
    };
}

function dlHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function prepareUrl(): string
{
    return '/api/backups/'.test()->backup->id.'/download/prepare';
}

/** Path + query of the signed link the status hands back. */
function fileUrlFrom(string $url): string
{
    $parts = parse_url($url);

    return $parts['path'].'?'.$parts['query'];
}

describe('the download button on an SFTP or FTP backup', function () {
    it('asks for a prepared download instead of saying it is unavailable', function () {
        $this->withHeaders(dlHeaders())
            ->getJson("/api/backups/{$this->backup->id}/download")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'prepare_required')
            ->assertJsonPath('message', __('backup.errors.download_prepare_required'));

        // Nothing was handed over, so nothing is recorded as downloaded.
        expect(ActivityLog::where('type', 'backup')->where('action', 'downloaded')->exists())->toBeFalse();
    });

    it('prepares the copy on the default queue and says so', function () {
        Queue::fake();

        $this->withHeaders(dlHeaders())
            ->postJson(prepareUrl())
            ->assertStatus(202)
            ->assertJsonPath('download_preparation.status', 'preparing')
            ->assertJsonPath('download_preparation.url', null)
            ->assertJsonPath('download_preparation.bytes_total', strlen(DL_ARCHIVE));

        Queue::assertPushedOn('default', FetchBackupForDownload::class);

        $this->withHeaders(dlHeaders())
            ->getJson(prepareUrl())
            ->assertOk()
            ->assertJsonPath('download_preparation.status', 'preparing');
    });

    it('goes from preparing to ready to the exact same bytes in the browser', function () {
        Queue::fake();
        $this->travelTo('2026-10-22 18:00:00');

        $this->withHeaders(dlHeaders())->postJson(prepareUrl())->assertStatus(202);

        $preparation = BackupDownloadPreparation::firstOrFail();
        app()->call([new FetchBackupForDownload($preparation->id), 'handle']);

        $status = $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertOk()
            ->assertJsonPath('download_preparation.status', 'ready')
            // Kept an hour, and the link says so.
            ->assertJsonPath('download_preparation.expires_at', '22-10-2026 19:00:00')
            ->assertJsonPath('download_preparation.bytes_total', strlen(DL_ARCHIVE));

        $url = $status->json('download_preparation.url');
        expect($url)->toContain('signature=')->toContain('preparation=');

        // Private to the panel account: the copy is the whole site.
        $path = $preparation->fresh()->path;
        expect(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600')
            ->and(substr(sprintf('%o', fileperms(dirname($path))), -4))->toBe('0700');

        $response = $this->withHeaders(dlHeaders())->get(fileUrlFrom($url))->assertOk();

        expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe(DL_ARCHIVE)
            ->and(hash_file('sha256', $response->baseResponse->getFile()->getPathname()))->toBe(hash('sha256', DL_ARCHIVE))
            ->and($response->headers->get('Content-Disposition'))->toContain('shop-example-test')
            ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream');

        $entry = ActivityLog::where('type', 'backup')->where('action', 'downloaded')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->user_id)->toBe($this->admin->id)
            ->and(json_encode($entry->properties))->not->toContain('signature');
    });

    it('falls back to a streamed copy when the driver has no direct path', function () {
        $this->driverMode = 'fallback';

        $this->withHeaders(dlHeaders())->postJson(prepareUrl())->assertStatus(202);

        $url = $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertJsonPath('download_preparation.status', 'ready')
            ->json('download_preparation.url');

        $response = $this->withHeaders(dlHeaders())->get(fileUrlFrom($url))->assertOk();

        expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe(DL_ARCHIVE);
    });

    it('works the same for an FTP destination', function () {
        $this->destination->update(['provider' => 'ftp', 'config' => ['host' => 'ftp.example.test', 'username' => 'u', 'password' => 'p']]);

        $this->withHeaders(dlHeaders())
            ->getJson("/api/backups/{$this->backup->id}/download")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'prepare_required');

        $this->withHeaders(dlHeaders())->postJson(prepareUrl())->assertStatus(202);

        $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertJsonPath('download_preparation.status', 'ready');
    });

    it('records the download once, not once per resumed range', function () {
        $this->withHeaders(dlHeaders())->postJson(prepareUrl());
        $url = fileUrlFrom($this->withHeaders(dlHeaders())->getJson(prepareUrl())->json('download_preparation.url'));

        $this->withHeaders(dlHeaders())->get($url)->assertOk();
        $this->withHeaders(dlHeaders() + ['Range' => 'bytes=5-'])->get($url)->assertStatus(206);

        expect(ActivityLog::where('type', 'backup')->where('action', 'downloaded')->count())->toBe(1);
    });
});

describe('when preparing fails', function () {
    it('says why, in the words the destination test uses, with a reference', function () {
        $this->driverMode = 'refused';

        $this->withHeaders(dlHeaders())->postJson(prepareUrl())->assertStatus(202);

        $status = $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertOk()
            ->assertJsonPath('download_preparation.status', 'failed')
            ->assertJsonPath('download_preparation.reason', 'invalid_credentials')
            ->assertJsonPath('download_preparation.url', null);

        expect($status->json('download_preparation.message'))
            ->toBe(__('backup.errors.download_prepare_failed', ['detail' => __('storage.test.invalid_credentials')]))
            ->and($status->json('download_preparation.reference'))->not->toBeEmpty();

        // The partial copy goes with the failure.
        expect(File::directories($this->downloadDir))->toBe([]);
    });

    it('fails with the storage reason when the fallback stream cannot be read either', function () {
        $this->driverMode = 'fallback';

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('fileSize')->andReturn(strlen(DL_ARCHIVE));
        $disk->shouldReceive('readStream')->andThrow(UnableToReadFile::fromLocation('shop/a1b2c3.tar.gz', 'connection reset'));
        $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(app(StorageDriverFactory::class), builder: fn (array $config) => $disk));

        $this->withHeaders(dlHeaders())->postJson(prepareUrl())->assertStatus(202);

        $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertJsonPath('download_preparation.status', 'failed')
            ->assertJsonPath('download_preparation.reason', 'unreachable')
            ->assertJsonPath('download_preparation.message', __('backup.errors.download_prepare_failed', ['detail' => __('storage.test.unreachable')]));
    });

    it('refuses a short copy rather than serving a corrupt archive', function () {
        $this->driverMode = 'short';

        $this->withHeaders(dlHeaders())->postJson(prepareUrl());

        $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertJsonPath('download_preparation.status', 'failed')
            ->assertJsonPath('download_preparation.reason', 'incomplete')
            ->assertJsonPath('download_preparation.message', __('backup.errors.download_prepare_incomplete'));
    });

    it('says the archive is gone when the storage server no longer has it', function () {
        $this->remote->delete('shop/a1b2c3.tar.gz');

        $this->withHeaders(dlHeaders())->postJson(prepareUrl());

        $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertJsonPath('download_preparation.status', 'failed')
            ->assertJsonPath('download_preparation.reason', 'missing')
            ->assertJsonPath('download_preparation.message', __('backup.errors.download_missing'));
    });

    it('starts a fresh copy on the next click', function () {
        $this->driverMode = 'refused';
        $this->withHeaders(dlHeaders())->postJson(prepareUrl());
        $first = BackupDownloadPreparation::firstOrFail()->reference;

        $this->driverMode = 'direct';
        $this->withHeaders(dlHeaders())->postJson(prepareUrl())
            ->assertStatus(202)
            ->assertJsonPath('download_preparation.status', 'preparing');

        $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertJsonPath('download_preparation.status', 'ready');

        expect(BackupDownloadPreparation::count())->toBe(1)
            ->and(BackupDownloadPreparation::firstOrFail()->reference)->not->toBe($first);
    });
});

describe('guards', function () {
    it('refuses before queueing anything when the disk cannot hold the copy', function () {
        Queue::fake();
        $this->backup->update(['size_bytes' => 1024 ** 5]); // 1 PiB

        $response = $this->withHeaders(dlHeaders())
            ->postJson(prepareUrl())
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup');

        expect($response->json('errors.backup.0'))->toContain('PB');
        Queue::assertNothingPushed();
        expect(BackupDownloadPreparation::count())->toBe(0);
    });

    it('hands back the same preparation on a second click', function () {
        Queue::fake();

        $first = $this->withHeaders(dlHeaders())->postJson(prepareUrl())->assertStatus(202);
        $second = $this->withHeaders(dlHeaders())->postJson(prepareUrl())->assertOk();

        expect($second->json('download_preparation.reference'))->toBe($first->json('download_preparation.reference'))
            ->and(BackupDownloadPreparation::count())->toBe(1);

        Queue::assertPushed(FetchBackupForDownload::class, 1);
    });

    it('hands back the ready copy rather than fetching it again', function () {
        $this->withHeaders(dlHeaders())->postJson(prepareUrl())->assertStatus(202);

        Queue::fake();

        $this->withHeaders(dlHeaders())->postJson(prepareUrl())
            ->assertOk()
            ->assertJsonPath('download_preparation.status', 'ready');

        Queue::assertNothingPushed();
    });

    it('sends at most two copies at once and tells the third to come back', function () {
        $this->withHeaders(dlHeaders())->postJson(prepareUrl());
        $url = fileUrlFrom($this->withHeaders(dlHeaders())->getJson(prepareUrl())->json('download_preparation.url'));

        // Two sends in flight.
        $one = Cache::lock('backup-download-slot:0', 60);
        $two = Cache::lock('backup-download-slot:1', 60);
        expect($one->get())->toBeTrue()->and($two->get())->toBeTrue();

        $this->withHeaders(dlHeaders())->get($url)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('reason', 'busy');

        expect(ActivityLog::where('type', 'backup')->where('action', 'downloaded')->exists())->toBeFalse();

        $one->release();

        $this->withHeaders(dlHeaders())->get($url)->assertOk();

        // And the slot it took is free again once the response has gone.
        $this->withHeaders(dlHeaders())->get($url)->assertOk();
        $two->release();
    });

    it('does not prepare what can be downloaded directly', function () {
        $s3 = StorageDestination::create([
            'name' => 'S3', 'provider' => 's3',
            'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
        ]);
        $this->backup->forceFill(['storage_destination_id' => $s3->id])->save();

        $this->withHeaders(dlHeaders())->postJson(prepareUrl())
            ->assertStatus(422)
            ->assertJsonPath('errors.backup.0', __('backup.errors.download_prepare_not_needed'));
    });

    it('refuses an unsigned or tampered link', function () {
        $this->withHeaders(dlHeaders())->postJson(prepareUrl());
        $url = fileUrlFrom($this->withHeaders(dlHeaders())->getJson(prepareUrl())->json('download_preparation.url'));

        $this->withHeaders(dlHeaders())
            ->get("/api/backups/{$this->backup->id}/download/file?preparation=".BackupDownloadPreparation::firstOrFail()->reference)
            ->assertForbidden();

        $this->withHeaders(dlHeaders())->get(str_replace('preparation=', 'preparation=x', $url))->assertForbidden();
    });
});

describe('cleanup', function () {
    it('removes the copy and its row once the hour is up, not before', function () {
        $this->travelTo('2026-10-22 18:00:00');
        $this->withHeaders(dlHeaders())->postJson(prepareUrl());
        $url = fileUrlFrom($this->withHeaders(dlHeaders())->getJson(prepareUrl())->json('download_preparation.url'));
        $directory = dirname(BackupDownloadPreparation::firstOrFail()->path);

        $this->travelTo('2026-10-22 18:59:00');
        $this->artisan('backups:prune-downloads')->assertSuccessful();
        expect(is_dir($directory))->toBeTrue()->and(BackupDownloadPreparation::count())->toBe(1);

        $this->travelTo('2026-10-22 19:00:01');

        // Past its hour the copy is no longer offered, even before the sweep.
        $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertJsonPath('download_preparation.status', 'expired')
            ->assertJsonPath('download_preparation.url', null);

        $this->artisan('backups:prune-downloads')->assertSuccessful();

        expect(is_dir($directory))->toBeFalse()
            ->and(BackupDownloadPreparation::count())->toBe(0);

        $this->withHeaders(dlHeaders())->get($url)->assertStatus(403);
    });

    it('serves nothing for an expired copy the sweep has not reached yet', function () {
        $this->withHeaders(dlHeaders())->postJson(prepareUrl());
        $preparation = BackupDownloadPreparation::firstOrFail();
        $url = fileUrlFrom($this->withHeaders(dlHeaders())->getJson(prepareUrl())->json('download_preparation.url'));

        // Expiry moved, signature still valid: the row is what decides.
        $preparation->update(['expires_at' => now()->subMinute()]);

        $this->withHeaders(dlHeaders())->get($url)
            ->assertStatus(410)
            ->assertJsonPath('reason', 'not_ready');
    });

    it('removes a preparation whose worker died', function () {
        Queue::fake();
        $this->withHeaders(dlHeaders())->postJson(prepareUrl());

        $this->travel(FetchBackupForDownload::timeoutSeconds() + 301)->seconds();

        // Not handed back as "preparing" for ever.
        $this->withHeaders(dlHeaders())->getJson(prepareUrl())
            ->assertJsonPath('download_preparation.status', 'expired');

        $this->artisan('backups:prune-downloads')->assertSuccessful();

        expect(BackupDownloadPreparation::count())->toBe(0);
    });

    it('sweeps a copy left behind with no row', function () {
        File::ensureDirectoryExists($this->downloadDir.'/orphaned-copy');
        file_put_contents($this->downloadDir.'/orphaned-copy/archive.tar.gz', 'x');

        $this->artisan('backups:prune-downloads')->assertSuccessful();

        expect(is_dir($this->downloadDir.'/orphaned-copy'))->toBeFalse();
    });

    it('removes the copy when the backup is deleted', function () {
        $this->withHeaders(dlHeaders())->postJson(prepareUrl());
        $directory = dirname(BackupDownloadPreparation::firstOrFail()->path);
        expect(is_dir($directory))->toBeTrue();

        $this->withHeaders(dlHeaders())
            ->deleteJson("/api/backups/{$this->backup->id}")
            ->assertNoContent();

        expect(is_dir($directory))->toBeFalse()
            ->and(BackupDownloadPreparation::count())->toBe(0);
    });
});

describe('permissions', function () {
    it('denies every step to someone who can only view backups', function () {
        $this->withHeaders(dlHeaders())->postJson(prepareUrl());
        $url = fileUrlFrom($this->withHeaders(dlHeaders())->getJson(prepareUrl())->json('download_preparation.url'));

        $user = User::factory()->create();
        grantPermission($user, 'backup');
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        // The guard remembers the admin from the requests above otherwise.
        app('auth')->forgetGuards();

        $this->withHeaders($headers)->postJson(prepareUrl())->assertForbidden();
        $this->withHeaders($headers)->getJson(prepareUrl())->assertForbidden();
        // A valid link is not enough on its own: it is not a credential.
        $this->withHeaders($headers)->get($url)->assertForbidden();
    });

    it('allows someone who manages backups', function () {
        $user = User::factory()->create();
        grantPermission($user, 'backup', manage: true);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        $this->withHeaders($headers)->postJson(prepareUrl())->assertStatus(202);
        $url = fileUrlFrom($this->withHeaders($headers)->getJson(prepareUrl())->assertOk()->json('download_preparation.url'));
        $this->withHeaders($headers)->get($url)->assertOk();
    });

    it('denies an unauthenticated request', function () {
        $this->postJson(prepareUrl())->assertUnauthorized();
        $this->getJson(prepareUrl())->assertUnauthorized();
        $this->getJson("/api/backups/{$this->backup->id}/download/file")->assertUnauthorized();
    });
});

it('has every new message in every locale', function () {
    $keys = [
        'download_prepare_required', 'download_prepare_not_needed', 'download_no_space',
        'download_prepare_no_space', 'download_prepare_failed', 'download_prepare_incomplete',
        'download_prepare_crashed', 'download_not_ready', 'download_busy',
    ];

    foreach (config('app.available_locales') as $locale) {
        foreach ($keys as $key) {
            expect(trans('backup.errors.'.$key, [], $locale))->not->toBe('backup.errors.'.$key, "{$locale} lacks {$key}");
        }

        expect(trans('backup.errors.download_no_space', [], $locale))->toContain(':needed')->toContain(':free');
        expect(trans('backup.errors.download_prepare_failed', [], $locale))->toContain(':detail');
    }

    expect(config('app.available_locales'))->toHaveCount(8);
});

it('has a migration that can be rolled back', function () {
    $migration = require database_path('migrations/2026_10_22_180000_create_backup_download_preparations_table.php');

    $migration->down();
    expect(Schema::hasTable('backup_download_preparations'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('backup_download_preparations'))->toBeTrue();
});

/*
 * The SFTP adapter's readStream() is `fopen('php://temp')` + `get()` into it,
 * the same whole-archive buffer the FTP one has. The driver must hand
 * phpseclib the destination path instead.
 */
it('downloads SFTP archives straight to disk, never through php://temp', function () {
    $method = new ReflectionMethod(SftpDriver::class, 'downloadTo');
    expect($method->getDeclaringClass()->getName())->toBe(SftpDriver::class);

    $source = file_get_contents(app_path('Services/Server/Backups/Storage/Drivers/SftpDriver.php'));
    $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

    expect($code)->toContain('->get($remote, $path)')
        ->and($code)->not->toContain('php://temp');
});
