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

    /**
     * apt is purging it right now.
     *
     * A separate state rather than simply hiding the version: the purge takes
     * minutes, and a card that vanished the moment the button was pressed
     * would leave the operator with nothing to look at while the thing they
     * asked for was still happening — and nothing to see if it failed.
     */
    case Removing = 'removing';

    /** Settled: trust what the runtime reports about itself. */
    case Ready = 'ready';

    /** The last attempt failed; `reason` says how. */
    case Failed = 'failed';
}
