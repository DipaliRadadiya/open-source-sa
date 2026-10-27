<?php

namespace App\Services\Server\Backups;

use App\Exceptions\NotEnoughBackupSpace;
use App\Services\Server\Backups\Storage\LocalBackupFolder;

/**
 * Keeps a backup to this server's own disk from filling it (#14).
 *
 * A full disk takes every site on the server down with it, which is worse
 * than a missed backup — so the backup is what gives way. "Too full" is less
 * than 10% of the disk or 2 GB free, whichever is more, after the archive is
 * written.
 *
 * A backup has no fixed size, so it is asked twice: before anything is
 * written, against an estimate; and once the archive exists, against its
 * real size, before it is stored.
 */
class LocalSpaceGuard
{
    public function __construct(private LocalBackupFolder $folder) {}

    /**
     * The free space that must be left on a disk of `$total` bytes.
     */
    public function reserve(int $total): int
    {
        $percent = (float) config('server.backups.local.min_free_percent', 10);
        $bytes = (int) config('server.backups.local.min_free_bytes', 2 * 1024 ** 3);

        return max((int) ceil($total * $percent / 100), $bytes);
    }

    /**
     * Refuse when writing `$bytes` more onto the folder's disk would leave
     * less than the reserve. A disk that cannot be measured is not refused
     * here: the folder check reports a missing folder on its own, and an
     * unmeasurable disk is not evidence of a full one.
     *
     * @throws NotEnoughBackupSpace
     */
    public function ensureRoomFor(string $path, int $bytes): void
    {
        ['free' => $free, 'total' => $total] = $this->folder->space($path);

        if ($free === null || $total === null) {
            return;
        }

        if ($free - $bytes < $this->reserve($total)) {
            throw new NotEnoughBackupSpace(sprintf(
                'not enough space on the backup disk: %d bytes needed, %d free, %d must stay free',
                $bytes,
                $free,
                $this->reserve($total),
            ));
        }
    }
}
