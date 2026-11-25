<?php

namespace App\Jobs\Concerns;

use App\Services\Server\Backups\StaleBackupReaper;

/**
 * Gives a unique job's lock an expiry tied to its own timeout.
 *
 * Without `uniqueFor`, Laravel takes the lock with `0` seconds (`Illuminate\Bus\UniqueLock`) and
 * `RedisLock::acquire()` reads that as `setnx` with no TTL. If the worker dies before releasing
 * it (OOM, `systemctl kill`, reboot), or the job was dispatched while no worker ran, every later
 * dispatch for that id is silently dropped: no error, no `failed_jobs` row, permanently.
 *
 * The grace is over the job's own timeout, never under it: a job still legally running must not
 * lose its lock and start twice. Expiring late costs one delayed run; early, two concurrent backups.
 */
trait ExpiresUniqueLock
{
    /**
     * Long enough that the worker has certainly given up first: the timeout kills the job, and
     * only then does the lock become collectable.
     *
     * Public because {@see StaleBackupReaper} applies the same grace to its heartbeat bound, so a
     * lock and a row never disagree about when a job is dead.
     */
    public const UNIQUE_LOCK_GRACE = 300;

    public function uniqueFor(): int
    {
        return $this->timeout + self::UNIQUE_LOCK_GRACE;
    }
}
