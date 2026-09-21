<?php

namespace App\Services\Server\Backups;

use App\Models\Backup;
use Illuminate\Support\Facades\Date;

/**
 * Turns a stream of byte counts into a row the panel can render.
 *
 * Holds the running total in memory and writes it to the backup row at most
 * once every {@see self::WRITE_EVERY_SECONDS}. The filter feeding it fires per
 * stream bucket — a few kilobytes — so an unthrottled write would mean millions
 * of UPDATEs against SQLite for one archive, and a progress indicator that
 * costs more than the upload it is describing.
 *
 * `progress_at` is stamped on every write, and it is the column that matters
 * most. It is the heartbeat {@see StaleBackupReaper} reads to tell a slow
 * backup from a dead one — a distinction no wall-clock timeout can make,
 * because "three hours" is a normal duration for a large archive on a thin link
 * and an instant death for one whose socket has closed.
 */
class UploadProgressReporter
{
    /**
     * Long enough that the write cost is noise against the transfer, short
     * enough that a human watching the panel sees it move.
     */
    private const WRITE_EVERY_SECONDS = 5;

    private int $transferred = 0;

    private ?int $lastWrittenAt = null;

    public function __construct(
        private readonly Backup $backup,
        private readonly ?int $total = null,
    ) {}

    /**
     * Record bytes read, and persist if the throttle has elapsed.
     */
    public function advance(int $bytes): void
    {
        $this->transferred += $bytes;

        $now = Date::now()->getTimestamp();

        if ($this->lastWrittenAt !== null && ($now - $this->lastWrittenAt) < self::WRITE_EVERY_SECONDS) {
            return;
        }

        $this->lastWrittenAt = $now;
        $this->write();
    }

    /**
     * Force the final figure out, whatever the throttle says.
     *
     * Without this the last partial interval is lost and a completed upload
     * shows 97%, which reads as a backup that stopped just short rather than
     * one that finished.
     */
    public function flush(): void
    {
        $this->write();
    }

    public function transferred(): int
    {
        return $this->transferred;
    }

    /**
     * Written with a bare query rather than `$backup->update()` on purpose: the
     * runner holds its own copy of this model for the whole backup and fills in
     * the manifest, size and status at the end from it. Refreshing that
     * in-memory instance from here would be a second writer to the same object,
     * and the step that finishes last would quietly win.
     */
    private function write(): void
    {
        Backup::query()->whereKey($this->backup->getKey())->update([
            'bytes_transferred' => $this->transferred,
            'bytes_total' => $this->total,
            'progress_at' => Date::now(),
        ]);
    }
}
