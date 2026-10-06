<?php

namespace App\Support;

/**
 * One spelling per address (F2B-01, old QA list).
 *
 * fail2ban turns `::ffff:1.2.3.4` into `1.2.3.4` before banning, so a guard
 * that compared strings let the mapped form through: banning
 * `::ffff:<your own IP>` on the sshd jail locked the caller out of SSH. Every
 * address is reduced to the form fail2ban will act on before any check
 * looks at it: IPv4-mapped IPv6 becomes the IPv4 address, and any other IPv6
 * is written the one way inet_ntop writes it.
 */
final class IpAddress
{
    public static function canonical(string $ip): string
    {
        $packed = @inet_pton(trim($ip));

        if ($packed === false) {
            return trim($ip);
        }

        // ::ffff:a.b.c.d — ten zero bytes, two 0xff, then the IPv4 address.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return (string) inet_ntop($packed);
    }
}
