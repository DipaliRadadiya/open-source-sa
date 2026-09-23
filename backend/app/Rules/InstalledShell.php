<?php

namespace App\Rules;

use App\Enums\LoginShell;
use App\Services\Server\SystemUsers\InstalledShells;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One of the panel's shells, and one this server has.
 *
 * Two messages, because they are two different mistakes: a path the panel
 * never offers is an invalid value, a shell the panel offers but this server
 * lacks is something the person can fix by installing it.
 */
class InstalledShell implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $shell = is_string($value) ? LoginShell::tryFrom($value) : null;

        if ($shell === null) {
            $fail('validation.in')->translate();

            return;
        }

        if (! app(InstalledShells::class)->isInstalled($shell->value)) {
            $fail('errors/system-user.shell_not_installed')->translate(['shell' => $shell->value]);
        }
    }
}
