<?php

namespace App\Jobs\Concerns;

/**
 * Gives a unique job's lock an expiry tied to its own timeout.
 *
 * Without `uniqueFor`, Laravel takes the lock with `0` seconds
 * (`Illuminate\Bus\UniqueLock`), and `RedisLock::acquire()` reads that as
 * `setnx` with no TTL — a key that never expires. The lock is released on
 * normal completion or failure, so this only shows up when the worker dies
 * without getting to either: OOM, `systemctl kill`, a reboot. From then on
 * every dispatch for that id is silently dropped. No error, no `failed_jobs`
 * row, no screen that shows it — the feature simply stops working for that one
 * site or version, permanently.
 *
 * It also covers the case that has nothing to do with dying: a job dispatched
 * while no worker is running takes the lock and nothing ever releases it, so
 * fixing the worker does not fix the feature.
 *
 * The grace is over the job's own timeout, not under it — a job still legally
 * running must never lose its lock and be started a second time alongside
 * itself. Expiring late costs one delayed run; expiring early costs two
 * concurrent backups of the same site.
 */
trait ExpiresUniqueLock
{
    /**
     * Long enough that the worker has certainly given up first — the timeout
     * kills the job, and only then does the lock become collectable.
     */
    private const UNIQUE_LOCK_GRACE = 300;

    public function uniqueFor(): int
    {
        return $this->timeout + self::UNIQUE_LOCK_GRACE;
    }
}
