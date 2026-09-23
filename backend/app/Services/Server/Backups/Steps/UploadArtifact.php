<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Exceptions\UploadStalled;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
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
    public function __construct(
        private DestinationDisk $disks,
        private StorageDriverFactory $drivers,
    ) {}

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
        $destination = $context->target->storageDestination;

        $size = filesize($context->archivePath);
        $reporter = new UploadProgressReporter($context->backup, $size === false ? null : $size);

        // The driver's own resumable upload first, if it has one. It is the
        // only path that can survive a failed chunk: `writeStream()` gives up
        // on the first error and reports it as "Not able to write the file"
        // with no cause attached, which at 100 GB means ~1048 chances to lose
        // an hour's work to a momentary 5xx.
        $previousMemoryLimit = ini_get('memory_limit');
        $this->raiseMemoryLimit();

        try {
            $uploaded = $this->drivers->for($destination)->uploadFrom(
                $destination,
                $key,
                $context->archivePath,
                // Bytes Google has committed, not bytes we have read: on this
                // path they are the same thing, and after a resume the
                // committed figure is the only one that is true.
                fn (int $committed) => $reporter->set($committed),
            );
        } catch (Throwable $e) {
            $reporter->flush();

            if ($this->stalled($e)) {
                throw new UploadStalled('the upload stopped transferring and was abandoned', previous: $e);
            }

            throw $e;
        } finally {
            if ($previousMemoryLimit !== false) {
                ini_set('memory_limit', $previousMemoryLimit);
            }
        }

        if ($uploaded) {
            $reporter->flush();

            $context->remoteKey = $key;
            $context->manifest['key'] = $key;

            return;
        }

        // No resumable path for this provider. Fall back to Flysystem, which
        // cannot resume — so the retry below restarts the transfer rather than
        // continuing it. Worth having anyway: a restart that succeeds beats a
        // failure, and FTP/SFTP have nothing better available.
        $disk = $this->disks->for($destination);

        $handle = fopen($context->archivePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('could not open the archive for upload');
        }

        // Raised for the length of the upload, and restored below. An archive
        // at or under 100 MB does not stream: the Drive adapter hands it to
        // Google's `MediaFileUpload`, which base64-encodes the whole thing in
        // memory. See `server.backups.upload_memory_limit`.
        $previousMemoryLimit = ini_get('memory_limit');
        $this->raiseMemoryLimit();

        // Progress is measured as the adapter reads — the only place every
        // driver behaves the same. See {@see UploadProgressFilter}.
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
            // Put the ceiling back even on failure. A queue worker outlives one
            // job, and leaving it raised would silently grant every later job
            // the same headroom.
            if ($previousMemoryLimit !== false) {
                ini_set('memory_limit', $previousMemoryLimit);
            }

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
     * Give the upload room, but never take room away.
     *
     * `max()` on the parsed byte values, so a host that already runs a higher
     * limit — or an unlimited `-1` — keeps it. Writing the configured value in
     * unconditionally would *lower* the ceiling on exactly those hosts, turning
     * a fix for small backups into a new failure for large ones.
     */
    private function raiseMemoryLimit(): void
    {
        $wanted = (string) config('server.backups.upload_memory_limit', '768M');
        $current = (string) ini_get('memory_limit');

        // Already unlimited. Nothing to raise, and writing a finite value here
        // would be a downgrade.
        if (trim($current) === '-1') {
            return;
        }

        if ($this->bytes($wanted) > $this->bytes($current)) {
            ini_set('memory_limit', $wanted);
        }
    }

    /**
     * `memory_limit` shorthand ("768M", "1G") as bytes.
     */
    private function bytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
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
