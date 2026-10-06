<?php

namespace App\Rules;

use App\Enums\BackupType;
use App\Models\Application;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A backup type has to match the kind of site it is configured for.
 *
 * `type` is otherwise validated with a bare `Rule::enum(BackupType::class)`,
 * which accepts every case the enum holds on every site. That is wrong in both
 * directions, and wrong **silently**, which is the reason this exists rather
 * than a comment:
 *
 *  - `volumes` on a WordPress site finds no volumes, uploads an archive with
 *    nothing in it, and reports success. The backup list then shows a green row
 *    for a site that is not protected.
 *  - `filesystem` on a container site archives its document root, which holds a
 *    compose file and nothing else — the dishonest artefact that kept container
 *    sites from having a Backups screen at all.
 *
 * A rule rather than a closure in `SaveBackupTargetRequest::after()`, because
 * the answer depends on the application and the same pairing will be needed
 * wherever a type is chosen — a scheduled target, an on-demand run, a restore
 * reading a type back off an old archive. A check duplicated across those is a
 * check that drifts.
 *
 * The site's kind is read from `serving_profile`, not from its site type's
 * class: the profile is what the panel already uses to decide how a site is
 * served, and a git site resolves its own profile from what the user picked
 * (see `ServingProfile::resolve()`), so the stored value is the honest answer.
 */
class BackupTypeForSite implements ValidationRule
{
    public function __construct(private readonly Application $application) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = BackupType::tryFrom(is_string($value) ? $value : '');

        // Not this rule's job. `Rule::enum` has already refused an unknown
        // value, and adding a second message for it would print two.
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
