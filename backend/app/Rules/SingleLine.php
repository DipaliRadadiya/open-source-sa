<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects carriage returns / line feeds — a newline in a value that ends up in
 * a cron.d file (command, or the name in the header comment) could inject
 * additional cron lines.
 */
class SingleLine implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/[\r\n]/', $value)) {
            $fail('errors/cronjob.no_newline')->translate();
        }
    }
}
