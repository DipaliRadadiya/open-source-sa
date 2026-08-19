<?php

namespace App\Services\Server\Backups;

use App\Enums\BackupStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupTarget;
use Illuminate\Support\Facades\Log;

/**
 * Closes out backup rows whose job is provably gone.
 *
 * A backup normally leaves `running` one of two ways: the runner catches the
 * failure, or `RunBackup::failed()` fires. Neither happens when the worker dies
 * without warning — `kill -9`, an OOM, a reboot mid-archive — and the row is
 * then stranded at `running` forever.
 *
 * That is not merely untidy. Every path that starts a backup refuses while one
 * is in flight, so a single stranded row means the site can never be backed up
 * again, with nothing on screen saying why and no way out short of editing the
 * database. A backup feature that has silently stopped backing up is the one
 * failure this feature cannot have.
 *
 * **The bound is borrowed, not invented.** {@see ExpiresUniqueLock}
 * already decided how long to wait before assuming a job is dead — the job's own
 * timeout plus a grace — and expires the queue lock on exactly that. Reusing it
 * means the lock and the row cannot disagree about whether a run is alive, and
 * the direction of the error stays the one that trait argued for: expiring late
 * costs a delayed run, expiring early costs two concurrent backups of the same
 * site competing for one disk.
 *
 * Reaped rows are marked `Failed` rather than deleted or ignored. Ignoring one
 * would let backups resume while the screen still showed a run in progress that
 * ended yesterday; deleting it would erase the only evidence that anything went
 * wrong.
 */
class StaleBackupReaper
{
    /**
     * Statuses that hold the in-flight guard closed.
     *
     * @var array<int, string>
     */
    public const IN_FLIGHT = [
        BackupStatus::Pending->value,
        BackupStatus::Running->value,
        BackupStatus::Verifying->value,
    ];

    /**
     * A run older than this cannot still be alive: the worker's own timeout has
     * passed and the queue lock is already collectable.
     */
    public static function staleAfterSeconds(): int
    {
        $job = new RunBackup(0);

        return $job->uniqueFor();
    }

    /**
     * Whether this row is old enough that nothing can still be working on it.
     *
     * Measured from `created_at`, not `started_at`: a job that never reached a
     * worker at all — dispatched while the queue was down — sits at `pending`
     * with no start time, and that is precisely one of the cases that strands a
     * target.
     */
    public function isStale(Backup $backup): bool
    {
        if (! in_array($backup->status->value, self::IN_FLIGHT, true)) {
            return false;
        }

        $startedAt = $backup->started_at ?? $backup->created_at;

        return $startedAt !== null
            && $startedAt->addSeconds(self::staleAfterSeconds())->isPast();
    }

    /**
     * Close out every abandoned run for a target. Returns how many were reaped.
     *
     * Called before the in-flight check on every path that starts a backup, so
     * that a stranded row unblocks itself the next time somebody asks for a
     * backup rather than needing to be noticed first.
     */
    public function reap(BackupTarget $target): int
    {
        $stale = Backup::query()
            ->where('backup_target_id', $target->id)
            ->whereIn('status', self::IN_FLIGHT)
            ->get()
            ->filter(fn (Backup $backup): bool => $this->isStale($backup));

        foreach ($stale as $backup) {
            $this->close($backup);
        }

        return $stale->count();
    }

    /**
     * Mark one run abandoned.
     *
     * `finished_at` is set to now rather than to when the job actually died,
     * which is unknowable — the point at which we noticed is the honest answer
     * and is the one a duration on screen can be computed from.
     */
    public function close(Backup $backup): void
    {
        Log::channel('server-ops')->warning('backup abandoned', [
            'feature' => 'backup',
            'backup' => $backup->id,
            'backup_target' => $backup->backup_target_id,
            'status' => $backup->status->value,
            'started_at' => $backup->started_at?->toIso8601String(),
        ]);

        $backup->update([
            'status' => BackupStatus::Failed,
            'reason' => 'abandoned',
            'finished_at' => now(),
        ]);
    }

    /**
     * Is there a run for this target that could genuinely still be working?
     *
     * Reaps first, so the answer is about live jobs rather than about rows.
     */
    public function hasLiveRun(BackupTarget $target): bool
    {
        $this->reap($target);

        return Backup::query()
            ->where('backup_target_id', $target->id)
            ->whereIn('status', self::IN_FLIGHT)
            ->exists();
    }
}
