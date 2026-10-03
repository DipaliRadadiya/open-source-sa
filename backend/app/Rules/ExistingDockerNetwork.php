<?php

namespace App\Rules;

use App\Services\Server\Docker\DockerResources;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Docker network that is legal to name and that is actually on this box.
 *
 * Two checks, in this order, because they fail for different reasons and the
 * user can act on only one of them:
 *
 *  - The shape. This value is written into a compose file and reaches a
 *    command line, so it gets the same rule the create endpoint uses. Checked
 *    first so a hostile value is refused without being handed to `docker`.
 *  - The existence. `external: true` makes Compose look the name up rather
 *    than create it, so a name that is not there stops being a typo in a form
 *    and becomes a container that will not start — with the failure arriving
 *    on the next deploy, long after the save that caused it.
 *
 * Deliberately not a foreign key or an enum: Docker owns these objects. One
 * can be removed by anyone with a shell between this check and the deploy, so
 * this narrows the window rather than closing it, and the supervisor's own
 * failure remains the backstop.
 */
class ExistingDockerNetwork implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $name = (string) $value;

        if (! DockerResources::validName($name)) {
            $fail(__('validation.docker_network_invalid'));

            return;
        }

        $exists = collect(app(DockerResources::class)->networks())
            ->contains(fn (array $network): bool => $network['name'] === $name);

        if (! $exists) {
            $fail(__('validation.docker_network_missing', ['name' => $name]));
        }
    }
}
