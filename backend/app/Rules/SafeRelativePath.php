<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A relative path under a site's document root, safe to hand to `find`,
 * `cat` or `tee` on the server.
 *
 * An empty string means the root itself and is always allowed. Anything else
 * is validated segment by segment.
 *
 * This stands between a client-supplied string and a server command run as the site's own Linux
 * user. ServerOps runs array arguments through Process with no shell, so shell metacharacters are
 * ordinary filename characters; the rule therefore denies only what is actually dangerous:
 *
 *  - traversal: `.`, `..`, an empty segment, or a leading `/`
 *  - a leading `-`, which `find`, `rm` and `mv` would read as an option
 *  - control characters, including tab and newline: the listing is parsed out of tab-separated
 *    `find -printf` output
 *  - invalid UTF-8, which cannot survive the JSON response
 *  - a segment over 255 bytes, which the filesystem refuses itself
 *  - a backslash: in a request it can only be part of a Linux filename (a zip written on Windows
 *    uses it as a separator, and `FileBrowser::extractedName()` converts those to `/` first)
 *
 * `isSafe()` is static because `FileBrowser::extract()` applies the same check to archive entry
 * names, which are just as attacker-influenced as a request field.
 */
class SafeRelativePath implements ValidationRule
{
    public static function isSafe(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        // First: `preg_match` with `/u` fails open on invalid UTF-8 instead of reporting a mismatch.
        if (! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        if (str_starts_with($value, '/') || str_contains($value, '\\')) {
            return false;
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }

            // `find` and `rm` read a leading dash as an option; array arguments do not prevent that.
            if (str_starts_with($segment, '-')) {
                return false;
            }

            // Tab and newline matter most: the listing is parsed from `find -printf` output.
            if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                return false;
            }

            // NAME_MAX, in bytes: refuse clearly here rather than ENAMETOOLONG from a command.
            if (strlen($segment) > 255) {
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
