<?php

namespace App\Services\Server\Databases;

/**
 * Strong DB-user password generator using a shell/SQL-safe alphabet (no
 * quotes/backslashes) so generated passwords never need special escaping.
 *
 * No `=` or `+` either (FS-C22): PrestaShop's installer cuts a password at
 * `=`, and `+` reads as a space in a database URL. Both are separators to
 * something a password gets pasted into.
 */
class DatabasePassword
{
    private const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789.-_';

    public static function generate(int $length = 20): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
