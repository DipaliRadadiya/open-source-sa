<?php

namespace App\Rules;

use App\Services\Server\Docker\DockerResources;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A name for a Docker object the panel is about to create.
 *
 * The inverse of {@see ExistingDockerNetwork} and {@see ExistingDockerVolume},
 * and the inversion is the point: those refuse a name that is NOT there, this
 * refuses one that already IS.
 *
 * Why refusing matters more than it looks. Creating is idempotent-ish at the
 * Docker level — `network create` on an existing name errors, `volume create`
 * succeeds and hands back the existing volume — so the tempting behaviour is
 * "create it if missing, use it if present". That reads as success and silently
 * attaches a new site to another site's network, or mounts another site's
 * database into it. A typo that happens to collide with a real name is exactly
 * the case that must not quietly work.
 *
 * So: if you want the existing one, pick it from the list. This field is for
 * making a new one, and it says so by failing.
 */
class NewDockerName implements ValidationRule
{
    /** @param  'network'|'volume'  $kind */
    public function __construct(private string $kind) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $name = (string) $value;

        if (! DockerResources::validName($name)) {
            $fail(__("validation.docker_{$this->kind}_invalid"));

            return;
        }

        $docker = app(DockerResources::class);

        $taken = $this->kind === 'network'
            ? collect($docker->networks())->contains(fn (array $row): bool => $row['name'] === $name)
            : collect($docker->volumes())->contains(fn (array $row): bool => $row['name'] === $name);

        if ($taken) {
            $fail(__("validation.docker_{$this->kind}_taken", ['name' => $name]));
        }
    }
}
