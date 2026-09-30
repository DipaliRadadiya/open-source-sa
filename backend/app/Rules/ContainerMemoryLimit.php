<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A memory ceiling in the shorthand Docker itself accepts.
 *
 * A rule rather than the bare regex this used to be inline, for two reasons the
 * regex could not cover. The first is the message: `regex` fails with "format is
 * invalid", and the mistake people make here is the unit — `512` meaning megabytes
 * (Docker reads a bare number as BYTES, and 512 bytes is below the 6m floor Docker
 * refuses outright) or `512MB`, which is not a suffix Docker knows. Saying so is
 * the difference between a field somebody fixes and one they guess at.
 *
 * The second is that the value now has two callers — container sites and
 * containerised databases — and a limit format duplicated across two forms is a
 * format that drifts.
 *
 * **Not bounded against the machine's RAM, deliberately**, unlike its CPU
 * counterpart. Over-committing memory is normal and useful: ten containers with a
 * 1g ceiling each on a 4GB box is a sane configuration, because a ceiling is what
 * a container may use rather than what it reserves. CPU has no equivalent reading
 * — a quota above the core count is simply untrue — which is why only that one has
 * a host-size rule.
 */
class ContainerMemoryLimit implements ValidationRule
{
    /**
     * Docker's own floor. Below this `docker run` refuses with "Minimum memory
     * limit allowed is 6MB", so accepting it here would mean a form that saved
     * and a container that would not start.
     */
    private const MINIMUM_BYTES = 6 * 1024 * 1024;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $raw = trim((string) $value);

        if (preg_match('/^(\d+)(b|k|m|g)?$/i', $raw, $matches) !== 1) {
            $fail(__('validation.custom.memory_limit.format'));

            return;
        }

        $bytes = (int) $matches[1] * match (strtolower($matches[2] ?? 'b')) {
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
