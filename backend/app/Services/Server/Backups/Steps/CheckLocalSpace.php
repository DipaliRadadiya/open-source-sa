<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\StorageProvider;
use App\Exceptions\Server\Storage\LocalBackupFolderException;
use App\Models\Backup;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\LocalSpaceGuard;
use App\Services\Server\Backups\Storage\LocalBackupFolder;

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

        $estimate = $this->estimate($context);

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
     * About how many bytes this backup will write, or null when nothing is
     * known — a target with no finished backup whose size was never measured.
     */
    private function estimate(BackupContext $context): ?int
    {
        $last = Backup::query()
            ->where('backup_target_id', $context->target->id)
            ->where('status', BackupStatus::Verified)
            ->whereNotNull('size_bytes')
            ->latest('id')
            ->value('size_bytes');

        if ($last !== null) {
            return (int) $last;
        }

        if ($context->isDatabaseTarget()) {
            return $this->known($context->database()?->size_bytes);
        }

        $application = $context->target->application;

        if ($application === null) {
            return null;
        }

        $files = $application->directory_size_bytes;
        $volumes = $application->volume_size_bytes;
        $database = fn (): ?int => $application->databases()->sum('size_bytes') ?: null;

        return match ($context->target->type) {
            BackupType::Filesystem => $this->known($files),
            BackupType::Database => $this->known($database()),
            BackupType::Full => $this->sum($files, $database()),
            BackupType::Volumes, BackupType::VolumesConfig => $this->known($volumes),
            // Compose files and a `.env`: a few kilobytes.
            BackupType::Config => null,
            default => null,
        };
    }

    private function known(mixed $bytes): ?int
    {
        return $bytes === null || (int) $bytes <= 0 ? null : (int) $bytes;
    }

    private function sum(mixed ...$parts): ?int
    {
        $known = array_filter(array_map(fn (mixed $part): ?int => $this->known($part), $parts), fn (?int $part): bool => $part !== null);

        return $known === [] ? null : array_sum($known);
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
