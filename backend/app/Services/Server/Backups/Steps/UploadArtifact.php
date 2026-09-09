<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\Storage\DestinationDisk;
use RuntimeException;

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

        try {
            // writeStream, not put: the whole point is never to hold the
            // archive in memory.
            $disk->writeStream($key, $handle);
        } finally {
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
     * Application, then date, then id. Grouped so a human can find one
     * application's backups in a bucket shared by several, and the id keeps
     * two runs in the same second from colliding.
     */
    /**
     * Where this archive lives on the destination.
     *
     * Named by the backup's `uid`, never its `id`. An id is an autoincrement
     * that only means anything inside one panel's database: reinstall the
     * panel, or restore its database from an older dump, and the counter
     * starts again — the next backup then writes to the key an existing
     * archive already holds, and `writeStream` is a PUT, so the old one is
     * gone with no error anywhere. Two panels sharing a destination and a
     * prefix collide the same way.
     *
     * Nothing recomputes this: the key is stored on the row and every reader
     * (delete, prune, download, restore) reads it back from there. So archives
     * written under the old scheme keep resolving without a branch anywhere.
     */
    private function objectKey(BackupContext $context): string
    {
        return sprintf(
            'backups/%s/%s/%s.tar.gz',
            $context->application()->domain ?: 'application-'.$context->application()->id,
            $context->backup->created_at?->format('Y-m-d') ?? date('Y-m-d'),
            $context->backup->uid,
        );
    }

    public function cleanup(BackupContext $context): void
    {
        // The remote object is the artefact; it stays.
    }
}
