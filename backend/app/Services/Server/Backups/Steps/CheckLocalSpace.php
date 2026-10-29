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
 * Every backup, whatever its destination (2026-10-29, Dipali). The archive is
 * always built in the panel's working folder first, so a backup to S3 or
 * Google Drive fills this server's disk exactly as a local one does — it only
 * gives the space back after the upload. A full disk takes every site on the
 * server down with it, so the backup is what gives way.
 *
 * A backup has no fixed size, so this is an estimate: the last finished backup
 * of the same target, or — for a target that has never finished one — the
 * current size of what it captures. That second figure is uncompressed and so
 * errs high, which is the safe side; before 2026-10-29 a first backup had no
 * estimate at all and could fill the disk while the archive was still being
 * built.
 *
 * A backup to this server's own disk (#14) is also asked whether its folder is
 * still there, and — when that folder is on another disk than the working
 * folder — whether that disk has room too. The real archive size is checked
 * again before it is stored, by the local driver.
 *
 * First in the list on purpose: a refusal here costs nothing, while one after
 * the archive is built has already spent the time.
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

        // Missing or not the panel's: a disk that is no longer mounted, most
        // likely. Said now, not after an hour of archiving.
        if ($path !== null && ($problem = $this->folder->problem($path)) !== null) {
            throw new LocalBackupFolderException($problem);
        }

        $estimate = $this->sizes->backup($context->target);

        if ($estimate === null) {
            return;
        }

        $context->manifest['space_estimate_bytes'] = $estimate;

        // Where the archive is built. On the backup disk that is the same space
        // the stored archive takes — storing it is a move — so one check covers
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
