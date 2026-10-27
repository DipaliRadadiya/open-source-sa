<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Enums\BackupStatus;
use App\Enums\StorageProvider;
use App\Exceptions\Server\Storage\LocalBackupFolderException;
use App\Models\Backup;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\LocalSpaceGuard;
use App\Services\Server\Backups\Storage\LocalBackupFolder;

/**
 * Before a backup to this server's own disk writes anything (#14): is the
 * folder still there, and will the backup fit?
 *
 * A backup has no fixed size, so this is an estimate — the last finished
 * backup of the same target. A target that has never finished one has no
 * estimate and is let through: the second check, against the archive's real
 * size before it is stored, still stands between it and a full disk.
 *
 * First in the list on purpose: a refusal here costs nothing, while one after
 * the archive is built has already spent the time.
 */
class CheckLocalSpace implements BackupStep
{
    public function __construct(
        private LocalBackupFolder $folder,
        private LocalSpaceGuard $space,
    ) {}

    public function key(): string
    {
        return 'check_local_space';
    }

    public function appliesTo(BackupContext $context): bool
    {
        return $context->target->storageDestination?->provider === StorageProvider::Local;
    }

    public function run(BackupContext $context): void
    {
        $destination = $context->target->storageDestination;
        $path = (string) $destination->configValue('path');

        // Missing or not the panel's: a disk that is no longer mounted, most
        // likely. Said now, not after an hour of archiving.
        if (($problem = $this->folder->problem($path)) !== null) {
            throw new LocalBackupFolderException($problem);
        }

        $estimate = Backup::query()
            ->where('backup_target_id', $context->target->id)
            ->where('status', BackupStatus::Verified)
            ->whereNotNull('size_bytes')
            ->latest('id')
            ->value('size_bytes');

        if ($estimate === null) {
            return;
        }

        $context->manifest['space_estimate_bytes'] = (int) $estimate;

        // The archive is built in the panel's working folder first. On the
        // backup disk that is the same space the stored archive takes; on
        // another disk the backup disk still receives all of it. Either way
        // the backup disk must hold one more archive of about this size.
        $this->space->ensureRoomFor($path, (int) $estimate);
    }

    public function cleanup(BackupContext $context): void
    {
        //
    }
}
