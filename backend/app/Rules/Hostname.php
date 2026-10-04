<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A hostname a site can be served on (bug #9).
 *
 * Creating a site, a staging copy or a clone, and changing a site's domain,
 * each checked `^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$` — which let `-bad-.example.com`
 * and `qa..example.com` through to a vhost and a certificate request, while
 * adding an alias domain already checked each label. One rule now, for all of
 * them: every label 1–63 characters of letters, digits and hyphens, never
 * starting or ending with a hyphen; at most 253 characters; and a last label
 * that is a real top-level domain — letters, or punycode (`xn--p1ai`, which
 * the old pattern refused: bug #11).
 *
 * An address made only of digits is an IP and gets its own message: the
 * server's IP then served this site instead of a 404 (bug #54).
 */
class Hostname implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 253) {
            $fail('errors/application.invalid_domain')->translate();

            return;
        }

        $labels = explode('.', $value);

        if (count($labels) >= 2 && array_filter($labels, fn (string $label) => ! ctype_digit($label)) === []) {
            $fail('errors/application.domain_is_ip')->translate();

            return;
        }

        $valid = count($labels) >= 2
            && array_filter($labels, fn (string $label) => preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)$/', $label) !== 1) === []
            && preg_match('/^([A-Za-z]{2,63}|xn--[A-Za-z0-9-]{1,59})$/', (string) end($labels)) === 1;

        if (! $valid) {
            $fail('errors/application.invalid_domain')->translate();
        }
    }
}
