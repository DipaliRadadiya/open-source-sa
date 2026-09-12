<?php

namespace App\Enums;

/**
 * Where a manually-started security update is.
 *
 * No `Pending`: unlike a panel update, nothing here is started by a detached
 * process that might never appear. The row is written before the job is
 * dispatched and the job is the only thing that moves it, so "queued but not
 * yet picked up" and "running" are the same state from the screen's point of
 * view — and inventing a distinction the UI cannot act on would only create a
 * state that needs explaining.
 *
 * `Running` is still the state that needs care, for a different reason: the
 * upgrade can restart the panel's own services, including the queue worker
 * executing it. A row is therefore also moved out of `Running` by age — see
 * SecurityUpdateTracker::reconcile(). Nothing assumes the worker survived.
 */
enum SecurityUpdateStatus: string
{
    /** The job holds the apt lock, or is waiting for it. */
    case Running = 'running';

    /** unattended-upgrades finished and reported no error. */
    case Succeeded = 'succeeded';

    /** It failed, or the run vanished. `reason` says which. */
    case Failed = 'failed';

    /**
     * A run in this state must not be re-opened or re-run.
     *
     * apt takes a box-wide lock, so a second run would not race the first — it
     * would sit behind it and then repeat its work.
     */
    public function inFlight(): bool
    {
        return $this === self::Running;
    }
}
