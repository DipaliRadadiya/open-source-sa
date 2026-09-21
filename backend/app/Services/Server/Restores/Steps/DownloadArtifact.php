<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
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
    public function __construct(
        private DestinationDisk $disks,
        private StorageDriverFactory $drivers,
    ) {}

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
        // The destination recorded on the backup, not the one its target
        // points at today. Reading it through the target meant repointing a
        // target sent a restore looking in a bucket the archive was never in —
        // and a restore is the one operation that has already taken a safety
        // backup and is about to overwrite a live site.
        $destination = $context->backup->destination();

        if ($destination === null) {
            throw new RuntimeException('the storage destination for this backup no longer exists');
        }

        $key = $context->backup->manifest['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('this backup has no artefact key recorded');
        }

        $disk = $this->disks->for($destination);

        if (! $disk->exists($key)) {
            // The row says the backup exists; the bucket disagrees. Better to
            // stop here than to take a safety backup and unpack nothing.
            throw new RuntimeException("the artefact {$key} is not on the destination");
        }

        $archive = $context->track($context->workingDirectory.'/restore.tar.gz');

        // Ask the driver for a direct download first.
        //
        // `readStream()` is not always a stream: the FTP adapter implements it
        // as `fopen('php://temp')` + `ftp_fget`, so the whole archive is
        // buffered before a byte reaches us — and that buffer spills into the
        // system temp directory, usually a tmpfs of a couple of gigabytes. A
        // 24 GB restore therefore failed with "Unable to read file" while the
        // transfer itself was healthy.
        //
        // The working directory is deliberately not on /tmp for exactly this
        // reason (`server.backups.working_dir`), so a driver able to write
        // there directly should. Drivers whose `readStream()` genuinely
        // streams return false and fall through to the copy below.
        if ($this->drivers->for($destination)->downloadTo($destination, $key, $archive)) {
            $context->archivePath = $archive;

            return;
        }

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
