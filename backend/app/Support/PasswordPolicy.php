<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * What the panel requires of a password, in one place and in two forms.
 *
 * `rule()` is what validation enforces. `describe()` is the same numbers as data, so a client
 * can show the requirements instead of hardcoding them and drifting when the rule changes.
 *
 * Deliberately not `uncompromised()`: it queries haveibeenpwned on every submission, and a
 * self-hosted panel without outbound access must not fail a registration because of it.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->mixedCase()->numbers();
    }

    /**
     * The policy as data, for a client that wants to state it up front.
     *
     * Booleans rather than a sentence: the wording belongs to the client, in its own locale.
     *
     * @return array<string, int|bool>
     */
    public static function describe(): array
    {
        return [
            'min_length' => self::MIN_LENGTH,
            'requires_mixed_case' => true,
            'requires_number' => true,
            'requires_symbol' => false,
        ];
    }
}
