<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A relative path under a site's document root, safe to hand to `find`,
 * `cat` or `tee` on the server.
 *
 * An empty string means the root itself and is always allowed. Anything else
 * is validated segment by segment: no `.`/`..` (traversal), no leading slash
 * or backslash (escapes the relative join), no null byte, and only a
 * conservative filename charset — allowlisted rather than denylisted, because
 * a denylist only ever knows the tricks someone has already thought of.
 *
 * This is the one thing standing between a client-supplied string and a
 * server command run as the site's own Linux user — get it wrong and the
 * user-scoping in FileBrowser is the only thing left holding the line.
 *
 * `isSafe()` is exposed as a static method because it is also the check
 * applied to archive entry names during extraction (`FileBrowser::extract()`)
 * — a zip archive's own internal paths are just as attacker-influenced as a
 * request field, and need exactly the same rule, not a second hand-rolled one
 * that could quietly drift from this one.
 */
class SafeRelativePath implements ValidationRule
{
    public static function isSafe(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        if (str_contains($value, "\0")) {
            return false;
        }

        if (str_starts_with($value, '/') || str_contains($value, '\\')) {
            return false;
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }

            if (preg_match('/^[A-Za-z0-9._\- ]+$/', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! self::isSafe($value)) {
            $fail('errors/application.unsafe_path')->translate();
        }
    }
}
