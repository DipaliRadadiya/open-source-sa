<?php

namespace App\Services\Server\Backups\Storage;

use App\Models\StorageDestination;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a filesystem for one storage destination.
 *
 * The disk is built on demand and never registered in `filesystems.disks`, so
 * a destination's credentials cannot bleed into any other code that resolves a
 * named disk.
 *
 * The prober and the uploader share this one definition: a `throw => false` in
 * either would swallow failures and report backups as written when they were not.
 * Per-provider knowledge lives in the `StorageDriver` implementations.
 */
class DestinationDisk
{
    /** @var Closure(array<string, mixed>): Filesystem */
    private Closure $builder;

    /**
     * @param  null|callable(array<string, mixed>): Filesystem  $builder
     *                                                                    Defaults to Storage::build(). Tests inject a fake.
     */
    public function __construct(
        private StorageDriverFactory $drivers,
        ?callable $builder = null,
    ) {
        $this->builder = $builder !== null
            ? Closure::fromCallable($builder)
            : static fn (array $config): Filesystem => Storage::build($config);
    }

    /**
     * The one place a destination can repair itself.
     *
     * Every path (upload, verify, prune, delete, restore, prober) goes through here, so a
     * repair runs on unattended scheduled backups too. `preflight()` only runs from the prober.
     * Only Google Drive has anything to repair. See {@see StorageDriver::heal()}.
     */
    public function for(StorageDestination $destination): Filesystem
    {
        $driver = $this->drivers->for($destination);

        $driver->heal($destination);

        // Read config *after* healing: a repair may replace the folder id this disk is built on.
        return ($this->builder)($driver->config($destination));
    }

    /**
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array
    {
        return $this->drivers->for($destination)->config($destination);
    }
}
