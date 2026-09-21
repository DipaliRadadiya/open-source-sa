<?php

use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\Drivers\GoogleDriveOauthDriver;
use App\Services\Server\Backups\Storage\GoogleDriveWorkspace;
use App\Services\Server\Backups\Storage\GoogleOauthTokens;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Google\Service\Drive;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Download answered a raw 500 for four providers out of five.
 *
 * `BackupController::download()` called `$disk->temporaryUrl()` unconditionally
 * and only the S3 adapter implements it, so FTP, SFTP and both Google Drive
 * destinations threw `RuntimeException: This driver does not support creating
 * temporary URLs` — for the whole life of the feature. It surfaced on
 * 2026-09-21, the day the first Drive backup finally got far enough to be
 * downloadable.
 *
 * The fix is a per-driver capability with a null default, mirroring the
 * existing `downloadTo(): bool`. Drive answers with `webContentLink`, which
 * authenticates by browser cookie — so it works for the operator whose Drive
 * holds the archive, and for nobody else. Nothing is shared and no permission
 * is widened; verified against the real 24 GB archive, whose `shared` flag
 * stayed false.
 */

/**
 * A Drive whose `files->listFiles()` returns whatever the test wants.
 *
 * @param  array<int, array{id?: string, link?: string|null, canDownload?: bool|null}>  $files
 */
function driveReturning(array $files): GoogleDriveWorkspace
{
    return new GoogleDriveWorkspace(fn () => new class($files) extends Drive
    {
        public function __construct(array $files)
        {
            $this->files = new class($files)
            {
                public function __construct(private array $files) {}

                public function listFiles(array $params)
                {
                    $made = array_map(function (array $f) {
                        $file = new Drive\DriveFile;
                        $file->setId($f['id'] ?? 'file-id');
                        $file->setWebContentLink($f['link'] ?? null);

                        if (array_key_exists('canDownload', $f)) {
                            $caps = new Drive\DriveFile\Capabilities;
                            $caps->setCanDownload($f['canDownload']);
                            $file->setCapabilities($caps);
                        }

                        return $file;
                    }, $this->files);

                    return new class($made)
                    {
                        public function __construct(private array $made) {}

                        public function getFiles(): array
                        {
                            return $this->made;
                        }
                    };
                }
            };
        }
    });
}

function driveDestination(): StorageDestination
{
    return StorageDestination::create([
        'name' => 'gDrive',
        'provider' => 'google_drive_oauth',
        'config' => [
            'client_id' => 'x.apps.googleusercontent.com',
            'client_secret' => 'secret',
            'refresh_token' => 'refresh',
            'folder_id' => 'folder',
        ],
    ]);
}

it('hands back the Drive link for an archive in the operator own Drive', function () {
    $driver = new GoogleDriveOauthDriver(
        app(GoogleOauthTokens::class),
        driveReturning([['link' => 'https://drive.google.com/uc?id=abc&export=download']]),
    );

    expect($driver->downloadUrl(driveDestination(), 'site.test/2026-09-21-1135-uid.tar.gz'))
        ->toBe('https://drive.google.com/uc?id=abc&export=download');
});

it('refuses rather than guessing when two archives share a name', function () {
    // Two matches would mean two backups share a uid. Handing over whichever
    // Drive listed first risks restoring the wrong site's data, which is worse
    // than saying Download is unavailable.
    $driver = new GoogleDriveOauthDriver(
        app(GoogleOauthTokens::class),
        driveReturning([['link' => 'https://a'], ['link' => 'https://b']]),
    );

    expect($driver->downloadUrl(driveDestination(), 'site.test/dup.tar.gz'))->toBeNull();
});

it('refuses when Google says the account cannot download its own file', function () {
    // A Workspace policy can forbid this. Handing over the link anyway sends
    // the operator to a Google error page with no explanation.
    $driver = new GoogleDriveOauthDriver(
        app(GoogleOauthTokens::class),
        driveReturning([['link' => 'https://a', 'canDownload' => false]]),
    );

    expect($driver->downloadUrl(driveDestination(), 'site.test/x.tar.gz'))->toBeNull();
});

it('returns null instead of throwing when Drive is unreachable', function () {
    // A missing link must degrade to "Download is unavailable". An exception
    // escaping here would be the same 500 this change exists to remove.
    $workspace = new GoogleDriveWorkspace(function () {
        throw new RuntimeException('invalid_grant');
    });

    $driver = new GoogleDriveOauthDriver(app(GoogleOauthTokens::class), $workspace);

    expect($driver->downloadUrl(driveDestination(), 'site.test/x.tar.gz'))->toBeNull();
});

it('leaves S3 to its signed URL and never invents one for FTP or SFTP', function () {
    // S3 returns null here deliberately: the controller falls through to
    // `temporaryUrl()`, which is narrower — it carries its own expiry rather
    // than depending on who the browser is signed in as. FTP and SFTP have no
    // URL concept at all, and the honest answer is that Download is
    // unavailable, not a link that cannot work.
    $factory = app(StorageDriverFactory::class);

    foreach (['s3', 'ftp', 'sftp'] as $provider) {
        $destination = StorageDestination::create([
            'name' => $provider,
            'provider' => $provider,
            'config' => $provider === 's3'
                ? ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's']
                : ['host' => 'h', 'username' => 'u', 'password' => 'p', 'root' => '/'],
        ]);

        expect($factory->for($destination)->downloadUrl($destination, 'a/b.tar.gz'))
            ->toBeNull("{$provider} must not invent a download URL");
    }
});
