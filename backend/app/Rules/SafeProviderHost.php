<?php

namespace App\Rules;

use App\Support\RemoteHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A self-hosted GitLab base URL the panel is allowed to call.
 *
 * This is the git feature's only SSRF surface (the other providers are pinned
 * to fixed hosts), so the URL must be https and must not point at the loopback
 * interface or the cloud metadata range — the two targets that turn an
 * outbound fetch into a local-privilege problem.
 *
 * Private LAN ranges are deliberately allowed: a self-hosted GitLab on the
 * same network is a normal deployment for this panel, and blocking it would
 * break a legitimate setup to defend against an actor who already holds the
 * `git` manage permission.
 */
class SafeProviderHost implements ValidationRule
{
    /**
     * The rule is shared between features, so the message keys are
     * injectable. A storage endpoint that fails this rule must not tell the
     * user to "enter a valid URL for the self-hosted instance" — that is
     * git's wording, and it is meaningless on a bucket form.
     */
    public function __construct(
        private string $invalidKey = 'errors/git.invalid_host',
        private string $blockedKey = 'errors/git.blocked_host',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            $fail($this->invalidKey)->translate();

            return;
        }

        if (strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            $fail($this->invalidKey)->translate();

            return;
        }

        $host = RemoteHost::canonical($parts['host']);

        // A host that cannot be interpreted the same way the HTTP client will
        // is one no decision can be made about, so it is refused as malformed
        // rather than range-checked. This used to range-check *only* hosts
        // that `filter_var` accepted as a dotted quad, which let every other
        // spelling of an address through untouched — `https://0177.0.0.1` and
        // `https://0251.0376.0251.0376` (octal for the cloud metadata
        // address) among them. See `RemoteHost`.
        if (RemoteHost::isUninterpretable($host)) {
            $fail($this->invalidKey)->translate();

            return;
        }

        if (RemoteHost::isBlocked($host)) {
            $fail($this->blockedKey)->translate();
        }
    }
}
