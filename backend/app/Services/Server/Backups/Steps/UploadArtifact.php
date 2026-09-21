<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Exceptions\UploadStalled;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\UploadProgressFilter;
use App\Services\Server\Backups\UploadProgressReporter;
use RuntimeException;
use Throwable;

/**
 * Streams the archive to the storage destination.
 *
 * Streamed, never read into memory: a site archive is routinely larger than
 * the PHP memory limit, and `file_get_contents` on it would kill the worker
 * with an out-of-memory error reported as a failed backup.
 */
class UploadArtifact implements BackupStep
{
    public function __construct(private DestinationDisk $disks) {}

    public function key(): string
    {
        return 'upload_artifact';
    }

    public function appliesTo(BackupContext $context): bool
    {
        return true;
    }

    public function run(BackupContext $context): void
    {
        if ($context->archivePath === null || ! is_file($context->archivePath)) {
            throw new RuntimeException('there is no archive to upload');
        }

        $key = $this->objectKey($context);
        $disk = $this->disks->for($context->target->storageDestination);

        $handle = fopen($context->archivePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('could not open the archive for upload');
        }

        // Progress is measured as the adapter reads, which is the only place
        // every driver behaves the same and — as the run that prompted this
        // proved — the only counter that moves at all during an upload. See
        // {@see UploadProgressFilter} for why this is a filter and not a
        // hand-rolled chunk loop.
        $size = filesize($context->archivePath);
        $reporter = new UploadProgressReporter($context->backup, $size === false ? null : $size);

        UploadProgressFilter::register();
        stream_filter_append($handle, UploadProgressFilter::NAME, STREAM_FILTER_READ, $reporter);

        try {
            // writeStream, not put: the whole point is never to hold the
            // archive in memory.
            $disk->writeStream($key, $handle);

            // The throttle swallows the last partial interval, and an upload
            // that finished while showing 97% reads as one that stopped short.
            $reporter->flush();
        } catch (Throwable $e) {
            // Record how far it actually got before rethrowing. A failed
            // backup that reports 19 of 24 GB is a different conversation from
            // one that reports zero — the first says the link died mid-flight,
            // the second says it never started — and losing that number here
            // would leave both looking identical on screen.
            $reporter->flush();

            if ($this->stalled($e)) {
                throw new UploadStalled(
                    'the upload stopped transferring and was abandoned',
                    previous: $e,
                );
            }

            throw $e;
        } finally {
            // The filter is deliberately *not* removed by hand. A read filter
            // that has reached EOF has nothing left to flush, and
            // `stream_filter_remove()` warns "Unable to flush filter, not
            // removing" when asked anyway — a warning on every successful
            // backup. Closing the handle detaches it, which is the next line.
            //
            // fclose even on failure — a leaked handle keeps the file alive on
            // disk after cleanup unlinks it, so the space is not reclaimed
            // until the worker exits.
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $context->remoteKey = $key;
        $context->manifest['key'] = $key;
    }

    /**
     * Did this failure come from the low-speed abort rather than a real error?
     *
     * Walks the whole chain, because Flysystem wraps the adapter's exception
     * and the adapter wraps Guzzle's — the cURL wording sits three links down
     * and `getMessage()` on the outermost is not it.
     */
    private function stalled(Throwable $e): bool
    {
        for ($link = $e; $link !== null; $link = $link->getPrevious()) {
            $message = strtolower($link->getMessage());

            if (str_contains($message, 'operation too slow')
                || str_contains($message, 'less than 1 bytes/sec')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where this archive lives on the destination.
     *
     * **One folder per application, archives directly inside it.** A Google
     * Drive destination writes into somebody's *personal* Drive, so the shape
     * of this path is not an implementation detail — it is what they see next
     * to their photos. Three things were wrong with the previous one:
     *
     * - A hardcoded `backups/` segment, inside a destination folder already
     *   named "… Backups …". Worse for anyone who fills in the destination's
     *   own **Key prefix** field, which exists for exactly this purpose: they
     *   got `backups/backups/`.
     * - A folder per day, which for most schedules holds exactly one file. The
     *   date belongs in the name, where it also sorts.
     * - A filename that was a bare uuid. `75599625-9245-4cf9-…` is the same
     *   unreadable-hash problem that made a folder named after a Drive id read
     *   as a compromise; a backup should say what it is without being opened.
     *
     * Named by the backup's `uid`, never its `id`. An id is an autoincrement
     * that only means anything inside one panel's database: reinstall the
     * panel, or restore its database from an older dump, and the counter
     * starts again — the next backup then writes to the key an existing
     * archive already holds, and `writeStream` is a PUT, so the old one is
     * gone with no error anywhere. Two panels sharing a destination and a
     * prefix collide the same way. The timestamp reads first because that is
     * what a human sorts by; the uid stays on the end because that is what
     * makes it unique.
     *
     * Nothing recomputes this: the key is stored on the row and every reader
     * (delete, prune, download, restore, the artefacts listing) reads it back
     * from there, and nothing walks the destination's directory tree. So
     * archives written under the old scheme keep resolving with no branch
     * anywhere and no migration.
     */
    private function objectKey(BackupContext $context): string
    {
        $createdAt = $context->backup->created_at ?? now();

        return sprintf(
            '%s/%s-%s.tar.gz',
            $context->application()->domain ?: 'application-'.$context->application()->id,
            $createdAt->format('Y-m-d-Hi'),
            $context->backup->uid,
        );
    }

    public function cleanup(BackupContext $context): void
    {
        // The remote object is the artefact; it stays.
    }
}
