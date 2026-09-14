<?php

namespace App\Services\Server\Backups\Storage;

use App\Contracts\StorageDriver;
use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\Drivers\FtpDriver;
use App\Services\Server\Backups\Storage\Drivers\GoogleDriveDriver;
use App\Services\Server\Backups\Storage\Drivers\S3Driver;
use App\Services\Server\Backups\Storage\Drivers\SftpDriver;

/**
 * Resolves the one driver that knows how to talk to a given destination.
 *
 * The map is explicit rather than derived from a naming convention: a typo in
 * a class name should be a missing-class error at boot, not a destination that
 * silently resolves to the wrong provider and uploads a site's database
 * somewhere nobody chose.
 */
class StorageDriverFactory
{
    /** @var array<string, class-string<StorageDriver>> */
    private const DRIVERS = [
        StorageProvider::S3->value => S3Driver::class,
        StorageProvider::Ftp->value => FtpDriver::class,
        StorageProvider::Sftp->value => SftpDriver::class,
        StorageProvider::GoogleDrive->value => GoogleDriveDriver::class,
    ];

    public function for(StorageDestination $destination): StorageDriver
    {
        return $this->forProvider($destination->provider);
    }

    public function forProvider(StorageProvider $provider): StorageDriver
    {
        // Resolved through the container so a driver can take dependencies
        // later without every call site learning about them.
        return app(self::DRIVERS[$provider->value]);
    }

    /**
     * Every provider the panel can currently store a destination for.
     *
     * Derived from the enum, not from a second hand-maintained list — the two
     * drifting apart is how a provider ends up selectable in the UI and
     * unresolvable at upload time.
     *
     * @return list<StorageProvider>
     */
    public function supported(): array
    {
        return array_values(array_filter(
            StorageProvider::cases(),
            fn (StorageProvider $p): bool => isset(self::DRIVERS[$p->value]),
        ));
    }
}
