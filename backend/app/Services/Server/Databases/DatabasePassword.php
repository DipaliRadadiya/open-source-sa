<?php

namespace App\Services\Server\Databases;

/**
 * Strong DB-user password generator using a shell/SQL-safe alphabet (no
 * quotes/backslashes) so generated passwords never need special escaping.
 */
class DatabasePassword
{
    private const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789.-_+=';

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
