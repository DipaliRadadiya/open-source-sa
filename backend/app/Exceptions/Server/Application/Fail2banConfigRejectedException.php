<?php

namespace App\Exceptions\Server\Application;

use RuntimeException;

/**
 * fail2ban loaded the site's jail on reload and refused it (bug #95).
 *
 * `fail2ban-client -t` passes configs the running daemon then rejects: a
 * failregex with no <HOST>, a maxretry that is not a number. The panel puts
 * the previous files back either way; this carries fail2ban's own words, so
 * the user is told what to fix rather than given a reference to quote.
 */
class Fail2banConfigRejectedException extends RuntimeException
{
    public function __construct(public readonly string $reference, public readonly string $output)
    {
        parent::__construct('fail2ban rejected the configuration');
    }
}
