<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a command containing a shell comment.
 *
 * A cron job's command is written inside `( … ) >> log 2>&1; echo exit…` on
 * one line, so a comment — `echo hi # nightly` — swallows the closing
 * parenthesis and the redirect with it: cron runs a broken line, nothing is
 * logged, not even the exit status, and the panel shows the job as fine.
 *
 * `#` only starts a comment at the beginning of a word and outside quotes, so
 * `echo "#tag"`, `a#b`, `$#` and URLs with a fragment are all left alone.
 */
class NoShellComment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::commentStart($value) !== null) {
            $fail('errors/cronjob.shell_comment')->translate();
        }
    }

    /**
     * Where a shell comment begins, or null. Public for Sync, which splits an
     * adopted job's comment off its command (it would otherwise be refused
     * on the job's first edit).
     */
    public static function commentStart(string $command): ?int
    {
        $single = false;
        $double = false;
        // Whether the next character would begin a new word. Tracked rather
        // than read off the previous character, because an escaped space
        // (`a\ #b`) does not end a word.
        $wordStart = true;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($single) {
                $single = $char !== "'";

                continue;
            }

            if ($char === '\\' && ! $double) {
                $i++; // the next character is literal and part of the word
                $wordStart = false;

                continue;
            }

            if ($double) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $double = false;
                }

                continue;
            }

            if ($char === '#' && $wordStart) {
                return $i;
            }

            if ($char === '"') {
                $double = true;
            } elseif ($char === "'") {
                $single = true;
            }

            $wordStart = str_contains(" \t;&|()<>", $char);
        }

        return null;
    }
}
