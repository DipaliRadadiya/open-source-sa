<?php

namespace App\Rules;

use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes when the value is a syntactically valid cron expression.
 */
class ValidCronExpression implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CronExpression::isValidExpression($value)) {
            $fail('errors/cronjob.invalid_expression')->translate();
        }
    }
}
