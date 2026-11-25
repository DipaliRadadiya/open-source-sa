<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Enums\StorageProvider;
use App\Exceptions\Server\Storage\LocalBackupFolderException;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\LocalSpaceGuard;
use App\Services\Server\Backups\Storage\LocalBackupFolder;
use App\Services\Server\Disk\SizeEstimate;

/**
 * Before a backup writes anything: will it fit on this server's disk?
 *
 * Applies to every backup, whatever its destination: the archive is always built in the panel's
 * working folder first, so a backup to S3 or Google Drive fills this disk like a local one until
 * the upload. A full disk takes every site down, so the backup is what gives way.
 *
 * The size is an estimate: the last finished backup of the same target, or for a first backup
 * the current (uncompressed, so high) size of what it captures.
 *
 * A backup to this server's own disk is also checked for its folder still being there and, when
 * that is another disk than the working folder, for room there too. The local driver checks the
 * real archive size again before storing it.
 *
 * First in the list on purpose: a refusal here costs nothing.
 */
class CheckLocalSpace implements BackupStep
{
    public function __construct(
        private LocalBackupFolder $folder,
        private LocalSpaceGuard $space,
        private SizeEstimate $sizes,
    ) {}

    public function key(): string
    {
        return 'check_local_space';
    }

    public function appliesTo(BackupContext $context): bool
    {
        return true;
    }

    public function run(BackupContext $context): void
    {
        $destination = $context->target->storageDestination;
        $local = $destination?->provider === StorageProvider::Local;
        $path = $local ? (string) $destination->configValue('path') : null;

        // Missing or not the panel's (likely an unmounted disk): fail now, not after archiving.
        if ($path !== null && ($problem = $this->folder->problem($path)) !== null) {
            throw new LocalBackupFolderException($problem);
        }

        $estimate = $this->sizes->backup($context->target);

        if ($estimate === null) {
            return;
        }

        $context->manifest['space_estimate_bytes'] = $estimate;

        // Where the archive is built. Storing it is a move, so on the backup disk one check covers
        // both; on another disk the backup disk receives all of it as well.
        $this->space->ensureRoomFor($context->workingDirectory, $estimate);

        if ($path !== null && ! $this->sameDisk($path, $context->workingDirectory)) {
            $this->space->ensureRoomFor($path, $estimate);
        }
    }

    public function cleanup(BackupContext $context): void
    {
        //
    }

    /**
     * Whether two paths sit on one filesystem. Unknown counts as different, so
     * both disks are asked rather than one being assumed.
     */
    private function sameDisk(string $a, string $b): bool
    {
        $first = @stat($a);
        $second = @stat($b);

        return $first !== false && $second !== false && $first['dev'] === $second['dev'];
    }
}
