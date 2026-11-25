<?php

namespace App\Rules;

use App\Services\Server\HostCpus;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A CPU limit this machine can actually give.
 *
 * Docker already refuses a quota above the core count; this rule moves that refusal to a
 * validation error under the field, before anything is saved. Otherwise the row is written, then
 * `compose up -d` fails and the panel shows a CPU limit the site does not have.
 *
 * Docker's bounds are mirrored: 0.01 to the core count. `cpus: 0` means no limit at all, which is
 * the empty field's job and must not be reachable by typing a zero. Fractions are allowed, to two
 * decimal places.
 */
class WithinHostCpus implements ValidationRule
{
    // Laravel skips ordinary rules when trim(value) is empty. A newline-only
    // quota must still be refused, while optional/null quotas remain allowed.
    public bool $implicit = true;

    /** Docker's floor, and the reason a bare `0` is not "unlimited" here. */
    private const MINIMUM = 0.01;

    public function __construct(private ?int $cores = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        // Check before this rule's own trim, as well as before HTTP trimming.
        if (strpbrk((string) $value, "\r\n") !== false) {
            $fail(__('validation.custom.cpu_limit.format'));

            return;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return;
        }

        // At most two decimal places. Refused rather than rounded: a rounded limit nobody chose.
        if (preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
            $fail(__('validation.custom.cpu_limit.format'));

            return;
        }

        $requested = (float) $raw;

        if ($requested < self::MINIMUM) {
            $fail(__('validation.custom.cpu_limit.positive', ['minimum' => self::MINIMUM]));

            return;
        }

        $cores = $this->cores ?? app(HostCpus::class)->count();

        if ($requested > $cores) {
            $fail(__('validation.custom.cpu_limit.too_many', ['cores' => $cores]));
        }
    }
}
