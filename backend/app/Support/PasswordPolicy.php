<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * What the panel requires of a password, in one place and in two forms.
 *
 * `rule()` is what validation enforces. `describe()` is the same numbers as
 * data, so a sign-up form can *show* the requirements instead of guessing at
 * them.
 *
 * Both matter, and the second is the one that was missing. The rule lived —
 * identically — inside six FormRequests, and no endpoint stated it, so a
 * frontend wanting to say "at least 10 characters, upper and lower case, and a
 * number" had to hardcode that sentence. A hardcoded description of a rule
 * nothing publishes drifts the first time the rule changes, and drifts
 * silently: the form keeps promising the old policy and the API keeps
 * rejecting against the new one.
 *
 * Deliberately not `uncompromised()`. That check asks haveibeenpwned over the
 * network on every password submission, and a self-hosted panel that may have
 * no outbound access should not fail a registration because it could not reach
 * a third party.
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
     * Booleans rather than a sentence: the wording belongs to whoever is
     * rendering it, in whichever of the eight locales they are rendering it
     * in, and an English string baked in here would be untranslatable.
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
