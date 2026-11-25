<?php

namespace App\Services\Server\Backups;

use App\Models\Backup;
use Illuminate\Support\Facades\Date;

/**
 * Turns a stream of byte counts into a row the panel can render.
 *
 * Holds the running total in memory and writes it to the backup row at most once every
 * {@see self::WRITE_EVERY_SECONDS}. The feeding filter fires per stream bucket (a few KB), so
 * an unthrottled write would be millions of UPDATEs for one archive.
 *
 * `progress_at` is stamped on every write: it is the heartbeat {@see StaleBackupReaper} reads
 * to tell a slow backup from a dead one, which no wall-clock timeout can.
 */
class UploadProgressReporter
{
    /**
     * Long enough that the write cost is noise, short enough that the panel visibly moves.
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
     * Record an absolute figure rather than an increment.
     *
     * The resumable uploader reports what **Google has committed**. After a resume that can move
     * backwards (sent bytes were never acknowledged); adding deltas would drift past the file size.
     */
    public function set(int $transferred): void
    {
        $this->transferred = $transferred;

        $now = Date::now()->getTimestamp();

        if ($this->lastWrittenAt !== null && ($now - $this->lastWrittenAt) < self::WRITE_EVERY_SECONDS) {
            return;
        }

        $this->lastWrittenAt = $now;
        $this->write();
    }

    /**
     * Force the final figure out, whatever the throttle says, so a finished upload does not
     * show 97%.
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
     * A bare query on purpose, not `$backup->update()`: the runner holds its own copy of the
     * model and fills in manifest, size and status at the end, so a second writer to that
     * object would let the last one silently win.
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
