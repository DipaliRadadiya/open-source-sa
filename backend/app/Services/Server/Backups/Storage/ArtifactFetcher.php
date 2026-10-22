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
 * ({@see FetchBackupForDownload}), which need exactly the same thing and
 * learnt the same lesson getting it.
 *
 * Streamed to disk rather than read into memory: a site archive is routinely
 * larger than the whole PHP memory limit, and `$disk->get()` would kill the
 * worker on exactly the large sites that matter.
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
        // Ask the driver for a direct download first.
        //
        // `readStream()` is not always a stream: the FTP adapter implements it
        // as `fopen('php://temp')` + `ftp_fget`, so the whole archive is
        // buffered before a byte reaches us — and that buffer spills into the
        // system temp directory, usually a tmpfs of a couple of gigabytes. A
        // 24 GB restore therefore failed with "Unable to read file" while the
        // transfer itself was healthy.
        //
        // The local path is deliberately not on /tmp for exactly this reason
        // (`server.backups.working_dir`), so a driver able to write there
        // directly should. Drivers whose `readStream()` genuinely streams
        // return false and fall through to the copy below.
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
