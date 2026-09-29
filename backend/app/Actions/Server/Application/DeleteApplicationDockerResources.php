<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Docker\DockerResources;

/**
 * Remove the Docker network and volumes a site was using, when asked to.
 *
 * Opt-in, like removing files and databases, and for the same reason: deleting a
 * panel record must not silently destroy data. A volume holding a Ghost site's
 * MySQL is exactly as unrecoverable as the database a LEMP site had.
 *
 * **The safety is in what it refuses.** A network is commonly shared — two sites
 * put on one network to talk to each other is the whole point of the feature —
 * and a volume can be mounted by more than one site. So each object is checked
 * against every OTHER site first, and skipped when anything else still names it.
 * Deleting the site you asked about must not break the one you did not.
 *
 * Runs before the application row is deleted, because the check needs to know
 * which sites remain and the row itself is one of the answers.
 */
class DeleteApplicationDockerResources
{
    public function __construct(
        private DockerResources $docker,
        private ActivityLogger $activityLogger,
        private ContainerSupervisor $containers,
    ) {}

    /**
     * Decide what may be removed, while the application still exists.
     *
     * **Two phases, and the split is not tidiness.** The decision needs the row:
     * "does another site mount this" is a query over applications, and after the
     * delete every volume looks unclaimed. But the REMOVAL needs the containers
     * gone: `docker volume rm` refuses a volume any container references, running or
     * not, and `docker network rm` refuses a network with an attached endpoint.
     *
     * Running both halves before deprovision was the first attempt and it failed on
     * a real box with `solo-net:remove-failed` — the site's own container was still
     * attached, because that is exactly what had not happened yet. This is the same
     * before/after split the controller already makes for databases.
     *
     * @return array{volumes: list<string>, network: ?string, kept: list<string>}
     */
    public function plan(Application $application): array
    {
        $removable = [];
        $kept = [];

        $volumes = collect((array) ($application->volume_mounts ?? []))
            ->pluck('volume')
            ->filter()
            ->unique()
            ->values();

        // One listing, then decisions. Asking Docker per object would be a process
        // per volume on a site that might have five.
        $state = collect($this->docker->volumes())->keyBy('name');

        // An EMPTY listing is not "no volumes exist" — `docker system df -v` is the
        // slowest call in this service and `volumes()` returns `[]` when it does not
        // answer. Treating that as "nothing to do" is how a delete reported success
        // having removed nothing and said nothing: measured on a real box, a volume
        // that appeared in neither `removed` nor `kept`.
        if ($volumes->isNotEmpty() && $state->isEmpty()) {
            $kept[] = 'unreadable:docker-volume-list';
        }

        foreach ($volumes as $name) {
            $volume = $state->get($name);

            if ($volume === null) {
                // Either already gone, or the listing could not be read. Both are
                // worth recording — silence here is what made the first failure
                // undiagnosable.
                $kept[] = $state->isEmpty() ? "{$name}:not-listed" : "{$name}:already-gone";

                continue;
            }

            // Another site mounts it, or a container that is not this site's holds
            // it. `sites` includes the one being deleted, so it is excluded by id
            // rather than by count — counting would keep every volume of every
            // site.
            $others = collect($volume['sites'] ?? [])
                ->reject(fn (array $site): bool => (int) $site['id'] === (int) $application->id);

            // **Not `in_use`.** This runs before `compose down`, so the site's OWN
            // container is still holding its own volume and `in_use` is true for
            // every volume of every running site — which made the whole flag a
            // no-op in the only case that matters. Measured on a real box: a site
            // deleted with the flag kept its volume and the log said so.
            //
            // The question is whether a FOREIGN container holds it. Compose names
            // a container `<project>-<service>-1`, and the project is
            // `sv-app-<id>`, so this site's own are recognisable by prefix.
            $ownPrefix = $this->containers->project($application).'-';

            $foreign = collect($volume['container_names'] ?? [])
                ->reject(fn (string $container): bool => str_starts_with($container, $ownPrefix));

            if ($others->isNotEmpty()) {
                $kept[] = "{$name}:mounted-by-".$others->pluck('name')->implode(',');

                continue;
            }

            if ($foreign->isNotEmpty()) {
                $kept[] = "{$name}:held-by-".$foreign->implode(',');

                continue;
            }

            $removable[] = $name;
        }

        $network = (string) ($application->docker_network ?? '');
        $removableNetwork = null;

        if ($network !== '') {
            $row = collect($this->docker->networks())->firstWhere('name', $network);

            $others = collect($row['sites'] ?? [])
                ->reject(fn (array $site): bool => (int) $site['id'] === (int) $application->id);

            // `built_in` last but absolutely: `bridge`, `host` and `none` are
            // Docker's own and a site could have been pointed at one.
            // Same reasoning for the network: its own containers are still
            // attached at this point, so only a FOREIGN attachment is a reason to
            // keep it.
            $ownPrefix = $this->containers->project($application).'-';

            $foreign = collect($row['containers'] ?? [])
                ->reject(fn (array $container): bool => str_starts_with((string) $container['name'], $ownPrefix));

            if ($row === null) {
                $kept[] = "{$network}:not-listed";
            } elseif ($row['built_in'] ?? false) {
                $kept[] = "{$network}:docker-owned";
            } elseif ($others->isNotEmpty()) {
                $kept[] = "{$network}:joined-by-".$others->pluck('name')->implode(',');
            } elseif ($foreign->isNotEmpty()) {
                $kept[] = "{$network}:attached-".$foreign->pluck('name')->implode(',');
            } else {
                $removableNetwork = $network;
            }
        }

        return ['volumes' => $removable, 'network' => $removableNetwork, 'kept' => $kept];
    }

    /**
     * Remove what the plan approved, once the containers are gone.
     *
     * Failures are recorded rather than thrown: the site itself is already deleted
     * by this point, and turning "a volume would not go" into a 500 would report
     * the whole delete as failed when the only thing it did not finish is cleanup
     * somebody can do by hand from the Docker page.
     *
     * @param  array{volumes: list<string>, network: ?string, kept: list<string>}  $plan
     * @return array{removed: list<string>, kept: list<string>}
     */
    public function apply(Application $application, array $plan): array
    {
        $removed = [];
        $kept = $plan['kept'];

        foreach ($plan['volumes'] as $name) {
            if ($this->docker->removeVolume($name)->failed()) {
                $kept[] = "{$name}:remove-failed";

                continue;
            }

            $removed[] = "volume:{$name}";
        }

        if ($plan['network'] !== null) {
            if ($this->docker->removeNetwork($plan['network'])->failed()) {
                $kept[] = "{$plan['network']}:remove-failed";
            } else {
                $removed[] = "network:{$plan['network']}";
            }
        }

        $this->activityLogger->log('application.docker_resources_removed', $application, [
            'removed' => $removed,
            // Recorded, because "we did not delete this" is the half somebody comes
            // looking for when they find a volume still on the server.
            'kept' => $kept,
        ]);

        return ['removed' => $removed, 'kept' => $kept];
    }
}
