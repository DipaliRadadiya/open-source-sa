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
     * @return array{removed: list<string>, kept: list<string>}
     */
    public function execute(Application $application): array
    {
        $removed = [];
        $kept = [];

        $volumes = collect((array) ($application->volume_mounts ?? []))
            ->pluck('volume')
            ->filter()
            ->unique()
            ->values();

        // One listing, then decisions. Asking Docker per object would be a process
        // per volume on a site that might have five.
        $state = collect($this->docker->volumes())->keyBy('name');

        foreach ($volumes as $name) {
            $volume = $state->get($name);

            // Not there: nothing to remove and nothing to warn about.
            if ($volume === null) {
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

            if ($others->isNotEmpty() || $foreign->isNotEmpty()) {
                $kept[] = $name;

                continue;
            }

            if (! $this->docker->removeVolume($name)->failed()) {
                $removed[] = "volume:{$name}";
            }
        }

        $network = (string) ($application->docker_network ?? '');

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

            $removable = $row !== null
                && ! ($row['built_in'] ?? false)
                && $others->isEmpty()
                && $foreign->isEmpty();

            if ($removable && ! $this->docker->removeNetwork($network)->failed()) {
                $removed[] = "network:{$network}";
            } elseif ($row !== null) {
                $kept[] = $network;
            }
        }

        $this->activityLogger->log('application.docker_resources_removed', $application, [
            'removed' => $removed,
            // Recorded, because "we did not delete this" is the half somebody will
            // come looking for when they find a volume still on the server.
            'kept' => $kept,
        ]);

        return ['removed' => $removed, 'kept' => $kept];
    }
}
