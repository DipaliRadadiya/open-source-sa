<?php

namespace App\Enums;

/**
 * Where an install is, from the screen's point of view.
 *
 * `Ready` is never written to the database. A finished install deletes its
 * row, so "ready" means "the disk says it is there and nothing is in flight" —
 * derived, not stored. Storing it would create a second answer to a question
 * the filesystem already answers, free to disagree with it.
 */
enum InstallStatus: string
{
    /** apt (or fnm) is running right now. */
    case Installing = 'installing';

    /** Settled: trust what the runtime reports about itself. */
    case Ready = 'ready';

    /** The last attempt failed; `reason` says how. */
    case Failed = 'failed';
}
