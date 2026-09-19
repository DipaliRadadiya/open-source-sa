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
 * named disk — across queue workers, Octane tasks, or a later request.
 *
 * Extracted so the connection prober and the backup uploader share one
 * definition. They had drifted once already: the prober's config carried a
 * `throw => false` that silently swallowed every failure, and a second copy of
 * that mistake in the uploader would have meant backups reporting success
 * while writing nothing.
 *
 * The per-provider knowledge moved out to `StorageDriver` implementations —
 * this class is now the seam that keeps every caller from having to know which
 * provider it got.
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
     * The seam, and therefore the one place a destination can repair itself.
     *
     * Every path reaches a destination through here — the uploader, the
     * verifier, prune, delete, restore's download and the connection prober —
     * so a repair placed here runs on a scheduled backup at 3am as readily as
     * on a button press. That matters: `preflight()` is only called by the
     * prober, so anything hung off it would fix the destination for whoever was
     * looking at the screen and leave the unattended run broken.
     *
     * For every provider but Google Drive this costs nothing — a bucket is not
     * something the panel created, so there is nothing it may recreate. See
     * {@see StorageDriver::heal()}.
     */
    public function for(StorageDestination $destination): Filesystem
    {
        $driver = $this->drivers->for($destination);

        $driver->heal($destination);

        // Config is read *after* healing, deliberately: a repair that replaced
        // the folder id has to be the one this disk is built on, or the very
        // operation that triggered the repair would still use the dead id.
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
