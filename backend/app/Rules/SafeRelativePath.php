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
 * This is the one thing standing between a client-supplied string and a
 * server command run as the site's own Linux user — get it wrong and the
 * user-scoping in FileBrowser is the only thing left holding the line.
 *
 * It used to allow only `[A-Za-z0-9._\- ]`, on the reasoning that an
 * allowlist beats a denylist because a denylist only knows the tricks someone
 * has already thought of. That argument is sound against *shell* injection —
 * and shell injection is not reachable here: ServerOps runs array arguments
 * through Process with no shell, so `;`, `$(…)` and friends are ordinary
 * characters in a filename and nothing parses them.
 *
 * What it cost was every real filename. `logo@2x.png`, `report (final).pdf`,
 * `résumé.pdf`, `发票.pdf`, anything with `+`, `&`, `'` or `#` — all rejected
 * as unsafe paths, which is what a user uploading a perfectly ordinary file
 * was told.
 *
 * So the rule now denies what is actually dangerous, and that list is
 * enumerable precisely because there is no shell:
 *
 *  - traversal: `.`, `..`, an empty segment, or a leading `/`
 *  - a leading `-`, which `find`, `rm` and `mv` would read as an option
 *  - control characters, including tab and newline: the listing is parsed
 *    out of tab-separated `find -printf` output, and a filename carrying a
 *    tab would shift every field after it
 *  - invalid UTF-8, which cannot survive the JSON response anyway
 *  - a segment over 255 bytes, which the filesystem refuses itself — better
 *    a clear message here than ENAMETOOLONG from a command
 *  - a backslash, which stays refused: a zip written on Windows uses it as a
 *    directory separator, and nothing here can tell that apart from a Linux
 *    filename that merely contains one
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

        // Checked before anything else: a string that is not valid UTF-8
        // cannot be returned in a JSON response, and `preg_match` with `/u`
        // would fail open on it rather than reporting a mismatch.
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

            // `find <path>` and `rm <path>` read a leading dash as an option,
            // and array arguments do not protect against that — only the
            // absence of such a name does.
            if (str_starts_with($segment, '-')) {
                return false;
            }

            // Null, tab, newline and the rest. Tab and newline are the ones
            // that matter beyond hygiene: the listing is parsed out of
            // tab-separated, newline-delimited `find -printf` output.
            if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                return false;
            }

            // NAME_MAX, in bytes rather than characters — the limit the
            // filesystem applies. A name over it fails at the syscall, and a
            // clear refusal here beats ENAMETOOLONG from a command.
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
