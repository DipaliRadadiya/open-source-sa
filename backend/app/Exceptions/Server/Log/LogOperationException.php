<?php

namespace App\Exceptions\Server\Log;

use App\Exceptions\Server\ServerOperationException;

/**
 * A log the panel was asked for and could not read.
 *
 * Distinct from the 404 for a source that does not exist and the 403 for one
 * the panel has no permission to open: both of those are answers. This is the
 * case where there is no answer, and the alternative to saying so was returning
 * an empty log — which reads as "nothing has happened here", the opposite of
 * the truth, on the screen someone opens to find out what happened.
 */
class LogOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/log.read_failed';
    }
}
