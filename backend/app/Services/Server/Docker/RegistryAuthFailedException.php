<?php

namespace App\Services\Server\Docker;

use Exception;

/**
 * The registry credential could not be written to disk.
 *
 * Its own type rather than a `ProvisioningFailedException`, because this is
 * reached from the deploy path AND from the test-connection endpoint, and only
 * one of those is provisioning. Callers translate it into whatever failure their
 * own surface reports.
 *
 * Carries the server-ops reference and nothing else. There is no safe detail to
 * add: the thing that failed to be written is the secret.
 */
class RegistryAuthFailedException extends Exception
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct("Could not write the registry credential (reference {$reference})");
    }
}
