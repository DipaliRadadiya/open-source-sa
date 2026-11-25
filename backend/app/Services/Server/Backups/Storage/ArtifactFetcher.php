<?php

namespace App\Services\Server\Backups\Storage;

use App\Jobs\FetchBackupForDownload;
use App\Models\StorageDestination;
use App\Services\Server\Restores\Steps\DownloadArtifact;
use RuntimeException;

/**
 * Copies one archive off a storage destination onto a local path.
 *
 * Shared by a restore ({@see DownloadArtifact}) and a prepared download
 * ({@see FetchBackupForDownload}).
 *
 * Streamed to disk, never read into memory: an archive is often larger than the PHP memory
 * limit, and `$disk->get()` would kill the worker.
 */
class ArtifactFetcher
{
    public function __construct(
        private DestinationDisk $disks,
        private StorageDriverFactory $drivers,
    ) {}

    /**
     * @throws RuntimeException when the archive cannot be opened or written;
     *                          the adapter's own exception when the
     *                          destination refuses (classify that one)
     */
    public function fetch(StorageDestination $destination, string $key, string $path): void
    {
        // Ask the driver for a direct download first: `readStream()` is not always a stream
        // (the FTP adapter buffers the whole archive in `php://temp`, which spills into a small
        // tmpfs and fails large restores). The local path is not on /tmp for that reason
        // (`server.backups.working_dir`). Drivers that really stream return false and fall
        // through to the copy below.
        if ($this->drivers->for($destination)->downloadTo($destination, $key, $path)) {
            return;
        }

        $source = $this->disks->for($destination)->readStream($key);

        if ($source === null || $source === false) {
            throw new RuntimeException('the artefact could not be opened for download');
        }

        $handle = fopen($path, 'wb');

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
    }
}
