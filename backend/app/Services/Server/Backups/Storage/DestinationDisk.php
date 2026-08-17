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
 */
class DestinationDisk
{
    /** @var Closure(array<string, mixed>): Filesystem */
    private Closure $builder;

    /**
     * @param  null|callable(array<string, mixed>): Filesystem  $builder
     *                                                                    Defaults to Storage::build(). Tests inject a fake.
     */
    public function __construct(?callable $builder = null)
    {
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
        return [
            'driver' => 's3',
            'key' => $destination->access_key,
            'secret' => $destination->secret_key,
            'region' => $destination->region ?: 'us-east-1',
            'bucket' => $destination->bucket,
            'endpoint' => $destination->endpoint ?: null,
            // Behind the prefix, so one destination shared by several
            // applications cannot read or overwrite another's artefacts.
            'root' => $destination->prefix ?: '',

            // Path-style only for a custom endpoint. MinIO, Wasabi and B2
            // route through the path; real AWS deprecated it and does not
            // support it for buckets in regions launched after 2019 — and an
            // empty endpoint *means* AWS.
            'use_path_style_endpoint' => filled($destination->endpoint),

            // MUST stay true. With `throw => false` the adapter swallows
            // failures and returns null/false, so an upload that never
            // happened looks identical to one that did — a backup reporting
            // success over an empty bucket.
            'throw' => true,

            // MUST stay true. Laravel defaults this to *false* and so
            // overrides Flysystem's own `true`, which leaves `@http.stream`
            // unset on GetObject: Guzzle then buffers the whole object into
            // memory and hands readStream() a stream over an already-loaded
            // body. DownloadArtifact's stream_copy_to_stream looks streamed
            // and isn't — a 5.8 GB archive OOMs the worker on exactly the
            // large sites that most need restoring.
            'stream_reads' => true,
        ];
    }
}
