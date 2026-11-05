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
 * Three checks, in this order, because they fail for different reasons and the
 * user can act on only one of them:
 *
 *  - The shape. This value is written into a compose file and reaches a
 *    command line, so it gets the same rule the create endpoint uses. Checked
 *    first so a hostile value is refused without being handed to `docker`.
 *  - Whether it may be joined at all (RC-28). Existing was the only question
 *    asked, and `host`, `none` and another site's private network all exist —
 *    so a site could be put on the server's own network, or next to somebody
 *    else's database. {@see NetworkIsolation} says which; answered from the
 *    name, so this refusal does not reach `docker` either.
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

        // The network the site is on now is not a move. The Container card sends
        // it back with every save, and refusing it would refuse a memory limit
        // over a choice made before this rule existed.
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
