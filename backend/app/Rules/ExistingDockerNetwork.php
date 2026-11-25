<?php

namespace App\Rules;

use App\Models\Application;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\Docker\NetworkIsolation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Docker network that is legal to name, that a container may join, and that
 * is actually on this box.
 *
 * Three checks, in this order:
 *
 *  - The shape. The value is written into a compose file and reaches a command line, so it gets
 *    the same rule as the create endpoint, before anything is handed to `docker`.
 *  - Whether it may be joined at all. `host`, `none` and another site's private network exist
 *    but must not be joined; {@see NetworkIsolation} decides from the name alone.
 *  - The existence. `external: true` makes Compose look the name up instead of creating it, so a
 *    missing network would only fail on the next deploy.
 *
 * Not a foreign key or an enum: Docker owns these objects and one can be removed between this
 * check and the deploy, so this narrows the window; the supervisor's own failure is the backstop.
 */
class ExistingDockerNetwork implements ValidationRule
{
    /**
     * @param  Application|null  $application  the site being moved, when there is
     *                                         one: its own networks may be joined,
     *                                         and the network it is on now may be
     *                                         kept. Null when creating a site or a
     *                                         database, which own none yet.
     */
    public function __construct(private ?Application $application = null) {}

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

        // The network the site is on now is not a move; the Container card sends it back with
        // every save, so refusing it would block unrelated edits.
        $staying = $this->application !== null
            && $name === (string) ($this->application->docker_network ?? '');

        $own = $this->application === null ? null : (int) $this->application->getKey();

        if (! $staying && ($refusal = NetworkIsolation::refusal($name, $own)) !== null) {
            $fail($refusal);

            return;
        }

        $exists = collect(app(DockerResources::class)->networks())
            ->contains(fn (array $network): bool => $network['name'] === $name);

        if (! $exists) {
            $fail(__('validation.docker_network_missing', ['name' => $name]));
        }
    }
}
