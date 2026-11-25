<?php

namespace App\Services\Server\Backups;

use php_user_filter;

/**
 * Counts an archive's bytes as the storage adapter reads them.
 *
 * A stream filter works for every driver (S3, FTP, SFTP, Drive) because each reads the archive
 * off a handle we opened; no per-driver chunk loop or vendor coupling.
 *
 * It measures reads, not sends: the count runs at most one chunk (100 MB for Drive) ahead of
 * what the remote acknowledged, which is fine for "is this moving or dead". Socket counters
 * (`wchar` does not count `sendto`) cannot tell that; the read side can.
 *
 * Writes are throttled by {@see self::update()}: a row update per bucket would be millions of
 * writes for one backup.
 */
class UploadProgressFilter extends php_user_filter
{
    public const NAME = 'panel.upload.progress';

    /**
     * Registered once, lazily: `stream_filter_register` throws on a duplicate name and a queue
     * worker runs many backups in one process.
     */
    public static function register(): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    #[\ReturnTypeWillChange]
    public function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;

            if ($this->params instanceof UploadProgressReporter) {
                $this->params->advance($bucket->datalen);
            }

            // Pass through unmodified: this filter only observes; an altered archive would be a
            // corrupt backup that still reported success.
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
