<?php

namespace App\Services\Server\Docker;

use Exception;

/**
 * A step in bringing a containerised database up failed.
 *
 * Carries the step and the server-ops log reference, so the caller can say which
 * part broke and the user can quote the reference — without the raw stderr
 * reaching the API, which for a database would mean a password in a response.
 */
class DatabaseContainerFailedException extends Exception
{
    public function __construct(
        public readonly string $step,
        public readonly string $reference,
    ) {
        parent::__construct("Database container failed at step [{$step}] (reference {$reference})");
    }
}
