<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Restores\RestoreContext;
use RuntimeException;

/**
 * Pulls the archive back down from the destination.
 *
 * Streamed to disk rather than read into memory: a site archive is routinely
 * larger than the whole PHP memory limit, and `$disk->get()` would kill the
 * worker on exactly the large sites that most need restoring.
 */
class DownloadArtifact implements RestoreStep
{
    public function __construct(private DestinationDisk $disks) {}

    public function key(): string
    {
        return 'download_artifact';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        return true;
    }

    public function run(RestoreContext $context): void
    {
        $target = $context->backup->target;

        if ($target?->storageDestination === null) {
            throw new RuntimeException('the storage destination for this backup no longer exists');
        }

        $key = $context->backup->manifest['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('this backup has no artefact key recorded');
        }

        $disk = $this->disks->for($target->storageDestination);

        if (! $disk->exists($key)) {
            // The row says the backup exists; the bucket disagrees. Better to
            // stop here than to take a safety backup and unpack nothing.
            throw new RuntimeException("the artefact {$key} is not on the destination");
        }

        $archive = $context->track($context->workingDirectory.'/restore.tar.gz');

        $source = $disk->readStream($key);

        if ($source === null || $source === false) {
            throw new RuntimeException('the artefact could not be opened for download');
        }

        $handle = fopen($archive, 'wb');

        if ($handle === false) {
            @fclose($source);

            throw new RuntimeException('the download could not be written to disk');
        }

        try {
            stream_copy_to_stream($source, $handle);
        } finally {
            @fclose($source);
            fclose($handle);
        }

        $context->archivePath = $archive;
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // Tracked; the runner removes it.
    }
}
