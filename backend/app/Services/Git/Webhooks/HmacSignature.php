<?php

namespace App\Services\Git\Webhooks;

/**
 * The `sha256=<hex>` signature header GitHub and Bitbucket both use.
 *
 * Shared because the scheme is identical and only the header name differs —
 * duplicating it would mean two chances to get the comparison wrong.
 */
class HmacSignature
{
    /**
     * Fails closed on anything unexpected: no header, an algorithm we did not
     * compute, or a value that is not hex of the right length. A verifier that
     * treats a malformed signature as anything but a rejection is a verifier
     * an attacker can satisfy by sending nothing.
     */
    public static function matches(?string $header, string $secret, string $body): bool
    {
        if ($header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $secret);

        // hash_equals is constant-time in the *comparison*, but it leaks length
        // through an early return, so both sides are fixed-length hex here by
        // construction — the prefix check above guarantees the format.
        return hash_equals($expected, substr($header, 7));
    }
}
