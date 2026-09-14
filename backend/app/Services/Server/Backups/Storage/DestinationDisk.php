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

    public function for(StorageDestination $destination): Filesystem
    {
        return ($this->builder)($this->config($destination));
    }

    /**
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array
    {
        return $this->drivers->for($destination)->config($destination);
    }
}
