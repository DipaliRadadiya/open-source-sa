<?php

namespace App\Rules;

use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes when the value is a cron expression that Linux cron itself will read.
 *
 * The library check alone is not enough: it also understands Quartz-style
 * extensions — `L` (last), `W` (weekday), `?`, `#` (nth weekday) — that the
 * cron daemon does not. `0 0 L * *` passed, was written to cron.d, and cron
 * logged "this crontab file will be ignored": the job never ran while the
 * panel showed its next run. So every field must also use only what cron's own
 * parser takes — numbers, `*`, ranges, lists, steps and month/day names — or
 * the whole expression be one of the `@` shortcuts.
 */
class ValidCronExpression implements ValidationRule
{
    /** The shortcuts both cron and the library understand. */
    private const SHORTCUTS = ['@yearly', '@annually', '@monthly', '@weekly', '@daily', '@midnight', '@hourly'];

    /**
     * One list element: a value or `*`, an optional range end, an optional
     * step. Values are numbers or three-letter names (jan, mon, …).
     */
    private const ELEMENT = '(?:\*|[0-9]+|[a-z]{3})(?:-(?:[0-9]+|[a-z]{3}))?(?:\/[0-9]+)?';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CronExpression::isValidExpression($value) || ! $this->cronReads($value)) {
            $fail('errors/cronjob.invalid_expression')->translate();
        }
    }

    private function cronReads(string $expression): bool
    {
        $expression = strtolower(trim($expression));

        if (str_starts_with($expression, '@')) {
            return in_array($expression, self::SHORTCUTS, true);
        }

        $fields = preg_split('/\s+/', $expression) ?: [];

        if (count($fields) !== 5) {
            return false;
        }

        foreach ($fields as $field) {
            if (preg_match('/^'.self::ELEMENT.'(?:,'.self::ELEMENT.')*$/', $field) !== 1) {
                return false;
            }
        }

        return true;
    }
}
