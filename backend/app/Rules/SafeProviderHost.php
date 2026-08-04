<?php

namespace App\Rules;

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

        $host = strtolower(trim($parts['host'], '[]'));

        $isIpv4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

        $blocked = $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '::1'
            || ($isIpv4 && (
                str_starts_with($host, '127.')
                || str_starts_with($host, '169.254.') // cloud metadata
                || str_starts_with($host, '0.')
            ));

        if ($blocked) {
            $fail($this->blockedKey)->translate();
        }
    }
}
