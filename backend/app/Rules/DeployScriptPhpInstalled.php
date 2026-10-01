<?php

namespace App\Rules;

use App\Services\Server\Applications\DeployScriptPhp;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Every `{PHPxx}` variable in a deploy script names a PHP version this server
 * has. {@see DeployScriptPhp}
 */
class DeployScriptPhpInstalled implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ($missing = app(DeployScriptPhp::class)->missing($value)) === []) {
            return;
        }

        $fail('deployment.script_php_missing')->translate([
            'variables' => implode(', ', $missing),
            'versions' => implode(', ', DeployScriptPhp::versionsOf($missing)),
        ]);
    }
}
