<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a PHP version the chosen application will not run on.
 *
 * The sibling of {@see SupportedNodeVersion}, written after the same bug
 * arrived on the other runtime. The PHP field is one shared `phpFields()`
 * select offering every version on the box, and `defaultPhpVersion()` opens on
 * the *newest* installed one — `FpmPhpStack::versions()` sorts descending. So a
 * server with PHP 8.5 offered PrestaShop 8.5 and pre-selected it.
 *
 * What that cost, on 2026-09-08: PrestaShop 8.2.1 vendors the old monolithic
 * `symfony/symfony`, which does not run on 8.5. The install died inside
 * `ProxyCacheWarmer->warmUp()`, twenty-one frames deep in someone else's vendor
 * directory, and reached the user as "Server operation failed." The shop was
 * downloaded, unpacked, chowned and handed a database first.
 *
 * Caught at the form for the reason the Node rule gives: by install time the
 * system user, the directory, the vhost and the database all exist, so this is
 * the difference between an error message and a half-built site to clean up.
 *
 * **The ceiling is compared as major.minor, not as a major series — this is
 * where it differs from the Node rule and the difference is the whole point.**
 * Node majors *are* the support series, so that rule compares majors and a
 * ceiling of `24` admits `24.7`. PHP's series is the minor: 8.1 and 8.5 share
 * a major, so comparing majors would admit exactly the version that broke.
 * `version_compare` on the two-part string is the correct comparison here, and
 * copying the Node rule verbatim would have reproduced the bug it was fixing.
 */
class SupportedPhpVersion implements ValidationRule
{
    public function __construct(
        private ?string $min,
        private ?string $max,
        private string $typeTitle,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // `nullable` handles the absent case: no version chosen means the
        // panel's configured default, which is not this rule's question.
        if (blank($value)) {
            return;
        }

        $version = (string) $value;

        if ($this->min !== null && version_compare($version, $this->min, '<')) {
            $fail($this->message());

            return;
        }

        if ($this->max !== null && version_compare($version, $this->max, '>')) {
            $fail($this->message());
        }
    }

    /**
     * Said the way the project says it, because "invalid" tells the user
     * nothing about what to pick instead.
     */
    private function message(): string
    {
        return __('validation.php_version_unsupported', [
            'type' => $this->typeTitle,
            'range' => match (true) {
                $this->min !== null && $this->max !== null => "{$this->min} – {$this->max}",
                $this->min !== null => "{$this->min}+",
                default => "≤ {$this->max}",
            },
        ]);
    }
}
