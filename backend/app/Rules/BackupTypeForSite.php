<?php

namespace App\Rules;

use App\Enums\BackupType;
use App\Models\Application;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A backup type has to match the kind of site it is configured for.
 *
 * A bare `Rule::enum(BackupType::class)` accepts every case on every site, and fails silently:
 *
 *  - `volumes` on a WordPress site finds no volumes, uploads an empty archive and reports
 *    success, so the list shows a green row for a site that is not protected.
 *  - `filesystem` on a container site archives its document root, which holds only a compose
 *    file.
 *
 * A rule rather than a closure in `SaveBackupTargetRequest::after()`, so every place a type is
 * chosen (scheduled target, on-demand run, restore) shares one check.
 *
 * The site's kind comes from `serving_profile`, not its site type's class: a git site resolves
 * its own profile from what the user picked (see `ServingProfile::resolve()`).
 */
class BackupTypeForSite implements ValidationRule
{
    public function __construct(private readonly Application $application) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = BackupType::tryFrom(is_string($value) ? $value : '');

        // `Rule::enum` has already refused an unknown value; a second message would print two.
        if ($type === null) {
            return;
        }

        $isContainerSite = $this->application->serving_profile === 'docker';

        if ($isContainerSite === $type->isContainer()) {
            return;
        }

        $fail($isContainerSite
            ? 'backup.errors.target_type_needs_container'
            : 'backup.errors.target_type_needs_hosted')->translate();
    }
}
