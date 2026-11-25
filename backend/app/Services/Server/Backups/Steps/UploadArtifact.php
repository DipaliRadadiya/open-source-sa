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
 * Streamed, never read into memory: archives routinely exceed the PHP memory limit.
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

        // The driver's resumable upload first, if it has one: it survives a failed chunk,
        // while `writeStream()` gives up on the first error with no cause attached.
        $previousMemoryLimit = ini_get('memory_limit');
        $this->raiseMemoryLimit();

        try {
            $uploaded = $this->drivers->for($destination)->uploadFrom(
                $destination,
                $key,
                $context->archivePath,
                // Bytes the provider has committed; after a resume only this figure is true.
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

        // No resumable path for this provider: fall back to Flysystem, which cannot resume,
        // so a retry restarts the transfer.
        $disk = $this->disks->for($destination);

        $handle = fopen($context->archivePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('could not open the archive for upload');
        }

        // Raised for the upload, restored below: the Drive adapter base64-encodes archives of
        // 100 MB or less in memory. See `server.backups.upload_memory_limit`.
        $previousMemoryLimit = ini_get('memory_limit');
        $this->raiseMemoryLimit();

        // Progress is measured as the adapter reads, the one place every driver behaves the
        // same. See {@see UploadProgressFilter}.
        UploadProgressFilter::register();
        stream_filter_append($handle, UploadProgressFilter::NAME, STREAM_FILTER_READ, $reporter);

        try {
            // writeStream, not put: never hold the archive in memory.
            $disk->writeStream($key, $handle);

            // The throttle swallows the last partial interval; flush so it does not stop at 97%.
            $reporter->flush();
        } catch (Throwable $e) {
            // Record how far it got before rethrowing.
            $reporter->flush();

            if ($this->stalled($e)) {
                throw new UploadStalled(
                    'the upload stopped transferring and was abandoned',
                    previous: $e,
                );
            }

            throw $e;
        } finally {
            // Restore the limit even on failure: the queue worker outlives one job.
            if ($previousMemoryLimit !== false) {
                ini_set('memory_limit', $previousMemoryLimit);
            }

            // The filter is not removed by hand: `stream_filter_remove()` warns after EOF.
            // Closing the handle detaches it. fclose even on failure: a leaked handle keeps the
            // file's disk space allocated until the worker exits.
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
     * A host already running a higher limit, or unlimited `-1`, keeps it.
     */
    private function raiseMemoryLimit(): void
    {
        $wanted = (string) config('server.backups.upload_memory_limit', '768M');
        $current = (string) ini_get('memory_limit');

        // Already unlimited: a finite value would be a downgrade.
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
     * Walks the whole chain: the cURL wording sits several wrapped exceptions down.
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
     * One folder per application, archives directly inside it, named `<timestamp>-<uid>`: the
     * timestamp sorts for humans, the uid makes it unique.
     *
     * Use the backup's `uid`, never its `id`: ids restart after a reinstall or database restore
     * (or collide across panels sharing a destination), and `writeStream` is a PUT that would
     * silently overwrite the old archive.
     *
     * The key is stored on the row and every reader uses it, so changing this scheme does not
     * affect archives already written.
     */
    private function objectKey(BackupContext $context): string
    {
        $createdAt = $context->backup->created_at ?? now();

        // A database gets its own `databases/` folder; a site's folder is its domain, which
        // cannot contain a slash, so the two never collide.
        $folder = $context->isDatabaseTarget()
            ? 'databases/'.$context->database()?->name.'-'.$context->target->database_id
            : ($context->application()->domain ?: 'application-'.$context->application()->id);

        return sprintf(
            '%s/%s-%s.tar.gz',
            $folder,
            $createdAt->format('Y-m-d-Hi'),
            $context->backup->uid,
        );
    }

    public function cleanup(BackupContext $context): void
    {
        // The remote object is the artefact; it stays.
    }
}
