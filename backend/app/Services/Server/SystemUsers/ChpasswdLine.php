<?php

namespace App\Services\Server\SystemUsers;

use InvalidArgumentException;

/**
 * The one line `chpasswd` reads for a user: `username:password`.
 *
 * `chpasswd` reads a *list* — every line on stdin is another account. So a
 * password carrying a newline is not one password, it is a second
 * instruction: `Secret123\nroot:Owned12345` sets this user's password AND
 * root's. Reproduced on a real server (2026-09-23): changing one user's
 * password changed another's.
 *
 * The FormRequests refuse control characters, and that is where a person
 * gets told. This is the second guard, at the only place the bytes turn
 * into a command, so a caller that skips validation — a job, a console
 * command, a future endpoint — still cannot write two lines. A colon needs
 * no guard: chpasswd splits on the first one, and a username cannot hold one.
 */
class ChpasswdLine
{
    /** Any control character — the FormRequests use the same pattern. */
    public const FORBIDDEN = '/[\x00-\x1F\x7F]/';

    public static function for(string $username, string $password): string
    {
        if (preg_match(self::FORBIDDEN, $username) || preg_match(self::FORBIDDEN, $password)) {
            throw new InvalidArgumentException('A chpasswd line cannot contain control characters.');
        }

        return $username.':'.$password;
    }
}
