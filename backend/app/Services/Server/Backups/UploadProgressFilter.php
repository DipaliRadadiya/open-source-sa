<?php

namespace App\Services\Server\Backups;

use php_user_filter;

/**
 * Counts an archive's bytes as the storage adapter reads them.
 *
 * **Why a stream filter and not a chunk loop.** The obvious way to report
 * upload progress is to drive the chunking by hand — and for Google Drive that
 * means reimplementing `StreamableUpload`'s resumable loop against a vendor
 * class, for one destination, leaving S3, FTP and SFTP exactly as blind as they
 * were. Every driver already has one thing in common: it reads the archive off
 * a handle we opened. Counting there measures all of them with no per-driver
 * code and no vendor coupling.
 *
 * It measures *reads*, not sends, and that gap is real but bounded: the adapter
 * reads a chunk then transmits it, so the count runs at most one chunk (100 MB
 * for Drive) ahead of what Google has acknowledged. For deciding "is this
 * moving or is it dead" — which is the entire question — a bounded lead is
 * irrelevant, and it is exactly what `/proc/<pid>/fdinfo` showed while
 * diagnosing the run that prompted this.
 *
 * **Reads are also the only place the truth was.** The worker that looked
 * frozen had a `wchar` that had not moved in ten minutes, because `wchar` does
 * not count `sendto`. Its `rchar` was climbing the whole time. The socket
 * counters lie about this and the read side does not.
 *
 * Writes are throttled by {@see self::update()} rather than happening per
 * bucket: a bucket is a few kilobytes, and a row update per bucket would be
 * millions of writes against SQLite for one backup — a progress indicator that
 * is itself the bottleneck.
 */
class UploadProgressFilter extends php_user_filter
{
    public const NAME = 'panel.upload.progress';

    /**
     * Registered once, lazily, because `stream_filter_register` throws on a
     * duplicate name and a queue worker runs many backups in one process.
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

            // Passed straight through, unmodified. This filter observes; an
            // archive that came out of it altered would be a corrupt backup
            // that still reported success.
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
