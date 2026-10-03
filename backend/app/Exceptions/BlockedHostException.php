<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A name that resolves to an address the panel must never connect to
 * (loopback, link-local, cloud metadata), found at connect time (bug #34).
 */
class BlockedHostException extends RuntimeException
{
    public function __construct(public readonly string $host)
    {
        parent::__construct("{$host} resolves to an address the panel does not connect to");
    }
}
