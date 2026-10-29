<?php

namespace App\Services\Server\Disk;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\StorageProvider;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;

/**
 * About how many bytes an action will write, from what the panel already
 * knows: the measured size of a site's folder, its databases and volumes, or
 * the last finished backup. Null (or 0) when nothing is known, which every
 * caller lets through — an unknown is not a refusal.
 *
 * Uncompressed sizes err high for an archive, which is the safe side.
 */
class SizeEstimate
{
    /** A backup of this target: its last finished one, else what it captures. */
    public function backup(BackupTarget $target): ?int
    {
        $last = Backup::query()
            ->where('backup_target_id', $target->id)
            ->where('status', BackupStatus::Verified)
            ->whereNotNull('size_bytes')
            ->latest('id')
            ->value('size_bytes');

        if ($last !== null) {
            return (int) $last;
        }

        if ($target->isDatabaseTarget()) {
            return $this->known($target->database?->size_bytes);
        }

        $application = $target->application;

        if ($application === null) {
            return null;
        }

        return match ($target->type) {
            BackupType::Filesystem => $this->files($application),
            BackupType::Database => $this->databases($application),
            BackupType::Full => $this->sum($this->files($application), $this->databases($application)),
            BackupType::Volumes, BackupType::VolumesConfig => $this->known($application->volume_size_bytes),
            // Compose files and a `.env`: a few kilobytes.
            BackupType::Config => null,
            default => null,
        };
    }

    /**
     * A restore: the archive when it has to be downloaded (a backup kept on
     * this server is linked, not copied), what it unpacks to — about the size
     * of what it replaces, else at least the archive — and, with the safety
     * copy on, the backup taken first.
     */
    public function restore(Backup $backup, string $type, bool $safetyCopy, ?Database $database = null): ?int
    {
        $archive = (int) $backup->size_bytes;
        $download = $backup->destination()?->provider === StorageProvider::Local ? 0 : $archive;
        $application = $backup->application;

        $unpacked = match (true) {
            $database !== null => $this->database($database),
            $application === null => null,
            $type === BackupType::Filesystem->value => $this->files($application),
            $type === BackupType::Database->value => $this->databases($application),
            $type === BackupType::Full->value => $this->sum($this->files($application), $this->databases($application)),
            in_array($type, [BackupType::Volumes->value, BackupType::VolumesConfig->value], true) => $this->known($application->volume_size_bytes),
            default => null,
        } ?? ($archive > 0 ? $archive : null);

        $target = $database !== null ? $database->backupTarget : $backup->target;
        $safety = $safetyCopy && $target !== null ? $this->backup($target) : null;

        return $this->sum($download > 0 ? $download : null, $unpacked, $safety);
    }

    /** The site's own folder, as last measured. */
    public function files(Application $application): ?int
    {
        return $this->known($application->directory_size_bytes);
    }

    /** Every database the site owns, as last measured. */
    public function databases(Application $application): ?int
    {
        return $this->known((int) $application->databases()->sum('size_bytes'));
    }

    public function database(Database $database): ?int
    {
        return $this->known($database->size_bytes);
    }

    public function sum(?int ...$parts): ?int
    {
        $known = array_filter($parts, fn (?int $part): bool => $part !== null && $part > 0);

        return $known === [] ? null : array_sum($known);
    }

    private function known(mixed $bytes): ?int
    {
        return $bytes === null || (int) $bytes <= 0 ? null : (int) $bytes;
    }
}
