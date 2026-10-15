<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings;

/**
 * Keep raw CPU line breaks visible to the field's validation rule.
 *
 * Normal CPU whitespace and every other field still use Laravel's transform,
 * including the existing file-content and password exceptions. Excluding CPU
 * from trimming altogether would also store padded valid decimals, which the
 * recipe renderer correctly refuses.
 */
class TrimStringsPreservingCpuLineBreaks extends TrimStrings
{
    protected function transform($key, $value): mixed
    {
        if ($key === 'cpu_limit' && is_string($value) && strpbrk($value, "\r\n") !== false) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
