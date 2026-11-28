<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a Node version the chosen application will not run on.
 *
 * Outside the supported range the site is created and reported active but serves a 502 (n8n, for
 * one, refuses to start). Refused at the form because by install time the system user, directory,
 * vhost and possibly a database already exist.
 *
 * Comparison is `version_compare` against the published major (or major.minor): a floor of `22`
 * admits every 22.x, 23.x and 24.x; a ceiling of `24` admits all of 24.x and refuses 25.
 */
class SupportedNodeVersion implements ValidationRule
{
    public function __construct(
        private ?string $min,
        private ?string $max,
        private string $typeTitle,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // No version chosen means the server's own Node, which is not this rule's question.
        if (blank($value)) {
            return;
        }

        if (! self::admits($this->min, $this->max, (string) $value)) {
            $fail($this->message());
        }
    }

    /**
     * Whether a version falls inside a type's range.
     *
     * Static and public because the panel also chooses a version that fits (a Node site created
     * without one is pinned at creation); one shared comparison keeps chooser and refuser in step.
     *
     * The ceiling is a major series, not a point release: `24` must admit `24.7.0`.
     */
    public static function admits(?string $min, ?string $max, string $version): bool
    {
        if ($min !== null && version_compare($version, $min, '<')) {
            return false;
        }

        return $max === null || self::major($version) <= self::major($max);
    }

    private static function major(string $version): int
    {
        return (int) explode('.', $version)[0];
    }

    private function message(): string
    {
        return __('validation.node_version_unsupported', [
            'type' => $this->typeTitle,
            'range' => self::describe($this->min, $this->max),
        ]);
    }

    /**
     * The range as the project states it, so the user knows what to pick instead.
     *
     * A floor at the start of the ceiling's major is that one major: `24`, not `24.0.0 – 24`.
     */
    public static function describe(?string $min, ?string $max): string
    {
        return match (true) {
            $min !== null && $max !== null && self::major($min) === self::major($max)
                && preg_match('/^\d+(\.0)*$/', $min) === 1 => (string) self::major($max),
            $min !== null && $max !== null => "{$min} – {$max}",
            $min !== null => "{$min}+",
            default => "≤ {$max}",
        };
    }
}
