<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\Storage\DestinationDisk;
use RuntimeException;

/**
 * Confirms the artefact is actually on the destination, at the size we sent.
 *
 * Without this the panel reports "backed up" on the strength of an upload call
 * that did not throw. That is a claim about our own code, not about the
 * bucket — and the moment it matters is the moment someone needs the backup,
 * which is the worst possible time to find out.
 *
 * Size, not checksum: an S3-compatible provider returns the object's size
 * cheaply in a HEAD, while verifying a checksum means downloading the whole
 * archive back, which for a multi-gigabyte site costs real money and time on
 * every single run. Size catches the failures that actually happen —
 * truncated uploads, silently dropped writes, a prefix the credentials cannot
 * write to.
 */
class VerifyArtifact implements BackupStep
{
    public function __construct(private DestinationDisk $disks) {}

    public function key(): string
    {
        return 'verify_artifact';
    }

    public function appliesTo(BackupContext $context): bool
    {
        return true;
    }

    public function run(BackupContext $context): void
    {
        if ($context->remoteKey === null) {
            throw new RuntimeException('there is no uploaded artefact to verify');
        }

        $disk = $this->disks->for($context->target->storageDestination);

        if (! $disk->exists($context->remoteKey)) {
            throw new RuntimeException('the uploaded artefact is not on the destination');
        }

        $remoteSize = (int) $disk->size($context->remoteKey);

        if ($remoteSize !== $context->sizeBytes) {
            throw new RuntimeException(sprintf(
                'uploaded %d bytes but the destination holds %d',
                $context->sizeBytes,
                $remoteSize,
            ));
        }

        $context->manifest['verified_bytes'] = $remoteSize;
    }

    public function cleanup(BackupContext $context): void
    {
        //
    }
}
