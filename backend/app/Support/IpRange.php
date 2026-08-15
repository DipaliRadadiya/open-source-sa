<?php

namespace App\Support;

/**
 * Whether an address could ever be reached from the public internet.
 *
 * Static and dependency-free on purpose: both the DNS verifier and the ACME
 * pre-check need this, and it is a property of the address itself, not
 * something either of them decides. Putting it on one of them would make the
 * other mock it.
 */
class IpRange
{
    /**
     * RFC1918, carrier-grade NAT (100.64/10, which cloud providers use), the
     * loopback range, link-local — including the cloud metadata address — and
     * the reserved blocks.
     */
    public static function isPrivate(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
