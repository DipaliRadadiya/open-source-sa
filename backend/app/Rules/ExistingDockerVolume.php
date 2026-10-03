<?php

namespace App\Rules;

use App\Services\Server\Docker\DockerResources;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Docker volume that is legal to name and that is on this box.
 *
 * The sibling of {@see ExistingDockerNetwork}, and the same two checks in the
 * same order: shape first, so a hostile value never reaches `docker`; then
 * existence, because `external: true` makes Compose look the name up.
 *
 * The consequence differs, though, and it is worse here. A missing network makes
 * the container refuse to start — loud, and obviously wrong. A missing volume
 * declared `external` also refuses, but the near-miss does not: point a site at
 * `ghost-dat` instead of `ghost-data` and, without `external`, Compose would
 * cheerfully create an EMPTY volume and the site would come up with none of its
 * data. That reads as data loss, and somebody restores a backup over a volume
 * that was fine all along.
 */
class ExistingDockerVolume implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $name = (string) $value;

        if (! DockerResources::validName($name)) {
            $fail(__('validation.docker_volume_invalid'));

            return;
        }

        $exists = collect(app(DockerResources::class)->volumes())
            ->contains(fn (array $volume): bool => $volume['name'] === $name);

        if (! $exists) {
            $fail(__('validation.docker_volume_missing', ['name' => $name]));
        }
    }
}
