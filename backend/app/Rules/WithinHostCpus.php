<?php

namespace App\Rules;

use App\Services\Server\HostCpus;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A CPU limit this machine can actually give.
 *
 * **The daemon does refuse an over-provisioned quota**, and loudly — measured on
 * the test box, both `docker run --cpus 16` and a compose file carrying `cpus: 16`
 * on a four-core host answer *"range of CPUs is from 0.01 to 4.00, as there are
 * only 4 CPUs available"*. So this rule is not standing between the user and a
 * silent clamp; it is moving a refusal that already happens from the wrong place
 * to the right one.
 *
 * The wrong place is what makes it worth a rule. Without this, saving the field
 * returns 200-and-then-fails: the row is written, `compose up -d` is attempted, the
 * daemon refuses, and the panel reports a provisioning failure with Docker's
 * sentence in it. The site itself is fine — compose refuses before it removes the
 * running container, so the old limit stays in force — but the panel is now showing
 * a CPU limit the site does not have, and the only account of why is a failure
 * card. As a validation error it is a message under the field, before anything is
 * saved, naming what the server has.
 *
 * Docker's own bounds are mirrored rather than invented: **0.01 to the core
 * count.** A value under 0.01 is accepted by the daemon and is not a limit anybody
 * means — 0.005 of a core is a container that cannot finish starting — and `cpus: 0`
 * means *no limit at all*, which is the empty field's job and must not be reachable
 * by typing a zero.
 *
 * Fractions are the point of the field, not an edge case: `0.5` is a sensible limit
 * for a sidecar, and two decimal places is finer than the CFS scheduler
 * meaningfully resolves.
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
        // The same rule protects recipe/direct create, container PUT and DBs.
        if (strpbrk((string) $value, "\r\n") !== false) {
            $fail(__('validation.custom.cpu_limit.format'));

            return;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return;
        }

        // A decimal with at most two places. Refused rather than rounded: a
        // silently rounded limit is a container running at a number nobody chose.
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
