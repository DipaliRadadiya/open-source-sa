<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A backup to this server's own disk refused because writing it would leave
 * the disk too full (#14). Recorded as its own reason rather than as the step
 * it happened in: "out of space" and "the upload failed" ask different things
 * of the person reading it.
 */
class NotEnoughBackupSpace extends RuntimeException
{
    public const REASON = 'not_enough_space';
}
