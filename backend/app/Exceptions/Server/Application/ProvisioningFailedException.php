<?php

namespace App\Exceptions\Server\Application;

use Exception;

/**
 * A provisioning step failed on the server. Carries the step that broke and
 * the server-ops log reference, so the user is told which part failed and can
 * quote the reference — without the raw stderr reaching the API.
 */
class ProvisioningFailedException extends Exception
{
    public function __construct(
        public readonly string $step,
        public readonly string $reference,
    ) {
        parent::__construct("Provisioning failed at step [{$step}] (reference {$reference})");
    }
}
