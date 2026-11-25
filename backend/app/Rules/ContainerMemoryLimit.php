<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A memory ceiling in the shorthand Docker itself accepts.
 *
 * A rule rather than a bare regex so the message can name the usual mistake, the unit: `512`
 * (Docker reads a bare number as BYTES) or `512MB` (not a suffix Docker knows). It is shared by
 * container sites and containerised databases so the format cannot drift between forms.
 *
 * **Not bounded against the machine's RAM, deliberately**, unlike its CPU counterpart:
 * over-committing memory is normal because a ceiling is what a container may use, not what it
 * reserves, while a CPU quota above the core count is simply untrue.
 */
class ContainerMemoryLimit implements ValidationRule
{
    /**
     * Docker's own floor ("Minimum memory limit allowed is 6MB"); below it the form would save
     * and the container would not start.
     */
    private const MINIMUM_BYTES = 6 * 1024 * 1024;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $raw = trim((string) $value);

        if (preg_match('/^(\d+)(b|k|m|g)?$/i', $raw, $matches) !== 1) {
            $fail(__('validation.custom.memory_limit.format'));

            return;
        }

        $unit = strtolower($matches[2] ?? '');

        // A bare number is a unit mistake and gets the format message: Docker reads `512` as bytes,
        // and "less than 6m" would hide the cause. `512b` names its unit, so the size check below
        // handles it.
        if ($unit === '') {
            $fail(__('validation.custom.memory_limit.format'));

            return;
        }

        $bytes = (int) $matches[1] * match ($unit) {
            'k' => 1024,
            'm' => 1024 * 1024,
            'g' => 1024 * 1024 * 1024,
            default => 1,
        };

        if ($bytes < self::MINIMUM_BYTES) {
            $fail(__('validation.custom.memory_limit.too_small'));
        }
    }
}
