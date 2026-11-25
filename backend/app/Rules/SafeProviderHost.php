<?php

namespace App\Rules;

use App\Support\RemoteHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A self-hosted GitLab base URL the panel is allowed to call.
 *
 * This is the git feature's only SSRF surface (the other providers use fixed hosts), so the
 * URL must be https and must not point at loopback or the cloud metadata range.
 *
 * Private LAN ranges are deliberately allowed: a GitLab on the same network is a normal
 * deployment, and the caller already holds the `git` manage permission.
 */
class SafeProviderHost implements ValidationRule
{
    /**
     * The rule is shared between features, so the message keys are injectable (git's
     * wording makes no sense on a storage bucket form).
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

        // A host the HTTP client may read differently (octal `https://0177.0.0.1`, ...) cannot
        // be range-checked, so refuse it as malformed. See `RemoteHost`.
        if (RemoteHost::isUninterpretable($host)) {
            $fail($this->invalidKey)->translate();

            return;
        }

        // Check what it resolves to as well as what was typed.
        if (RemoteHost::resolvesToBlocked($host)) {
            $fail($this->blockedKey)->translate();
        }
    }
}
