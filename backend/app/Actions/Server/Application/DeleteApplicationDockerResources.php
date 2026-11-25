<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Docker\DockerResources;
use App\Services\Server\Docker\OriginalNetwork;

/**
 * Remove the Docker network and volumes a site was using, when asked to.
 *
 * Opt-in, like removing files and databases: deleting a panel record must not silently destroy
 * data.
 *
 * The safety is in what it refuses: networks and volumes can be shared, so each object is checked
 * against every OTHER site first and skipped when anything else still names it.
 *
 * Runs before the application row is deleted, because the check needs to know which sites remain.
 */
class DeleteApplicationDockerResources
{
    public function __construct(
        private DockerResources $docker,
        private ActivityLogger $activityLogger,
        private ContainerSupervisor $containers,
        private OriginalNetwork $originalNetwork,
    ) {}

    /**
     * Decide what may be removed, while the application still exists.
     *
     * Two phases: the decision needs the row ("does another site mount this" is a query over
     * applications), but the removal needs the containers gone, because `docker volume rm` and
     * `docker network rm` refuse objects a container still references or is attached to.
     *
     * @return array{volumes: list<string>, networks: list<string>, kept: list<string>}
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

        // One listing, then decisions: asking Docker per volume would spawn a process each.
        $state = collect($this->docker->volumes())->keyBy('name');

        // An EMPTY listing is not "no volumes exist": `volumes()` returns `[]` when Docker does not
        // answer, and treating that as "nothing to do" reports success having removed nothing.
        if ($volumes->isNotEmpty() && $state->isEmpty()) {
            $kept[] = 'unreadable:docker-volume-list';
        }

        foreach ($volumes as $name) {
            $volume = $state->get($name);

            if ($volume === null) {
                // Already gone, or the listing could not be read. Record both.
                $kept[] = $state->isEmpty() ? "{$name}:not-listed" : "{$name}:already-gone";

                continue;
            }

            // `sites` includes the one being deleted, so exclude it by id, not by count.
            $others = collect($volume['sites'] ?? [])
                ->reject(fn (array $site): bool => (int) $site['id'] === (int) $application->id);

            // Not `in_use`: this runs before `compose down`, so the site's OWN container still holds
            // the volume. Ask whether a FOREIGN container holds it; this site's own are recognised
            // by the compose project prefix (`sv-app-<id>-<service>-1`).
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
        $removableNetworks = [];
        $networks = $network !== '' ? collect($this->docker->networks()) : collect();

        if ($network !== '') {
            $row = $networks->firstWhere('name', $network);

            $others = collect($row['sites'] ?? [])
                ->reject(fn (array $site): bool => (int) $site['id'] === (int) $application->id);

            // Same as volumes: only a FOREIGN attachment keeps the network. `built_in` is always
            // kept: `bridge`, `host` and `none` are Docker's own.
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
                $removableNetworks[] = $network;
            }
        }

        if (($original = $this->originalNetwork->find($application, $networks, $kept)) !== null) {
            $removableNetworks[] = $original;
        }

        return ['volumes' => $removable, 'networks' => $removableNetworks, 'images' => [], 'kept' => $kept];
    }

    /**
     * The images this site's containers were created from that nothing else uses.
     *
     * Asked before `compose down`, while the containers still say which images they run; removed
     * after it by {@see apply()}. Images used by any other container are kept, and so is everything
     * when Docker cannot be asked.
     *
     * @param  array{volumes: list<string>, networks: list<string>, images?: list<string>, kept: list<string>}  $plan
     * @return array{volumes: list<string>, networks: list<string>, images: list<string>, kept: list<string>}
     */
    public function planImages(Application $application, array $plan): array
    {
        $containers = collect($this->docker->containers());
        $project = $this->containers->project($application);
        $own = $containers->where('project', $project);

        if ($containers->isEmpty()) {
            $plan['kept'][] = 'unreadable:docker-container-list';

            return $plan + ['images' => []];
        }

        $plan['images'] = [];

        foreach ($own->pluck('image')->filter()->unique() as $image) {
            $others = $containers->filter(fn (array $container): bool => $container['image'] === $image && $container['project'] !== $project);

            if ($others->isNotEmpty()) {
                $plan['kept'][] = "image:{$image}:used-by-".$others->pluck('name')->implode(',');

                continue;
            }

            $plan['images'][] = $image;
        }

        return $plan;
    }

    /**
     * Remove what the plan approved, once the containers are gone.
     *
     * Failures are recorded, not thrown: the site is already deleted, so a failed cleanup must not
     * turn the whole delete into a 500.
     *
     * @param  array{volumes: list<string>, networks: list<string>, images?: list<string>, kept: list<string>}  $plan
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

        foreach ($plan['networks'] as $name) {
            if ($this->docker->removeNetwork($name)->failed()) {
                $kept[] = "{$name}:remove-failed";

                continue;
            }

            $removed[] = "network:{$name}";
        }

        // Images last: Docker refuses an image a container still uses.
        foreach ($plan['images'] ?? [] as $image) {
            if ($this->docker->removeImage($image)->failed()) {
                $kept[] = "image:{$image}:remove-failed";

                continue;
            }

            $removed[] = "image:{$image}";
        }

        $this->activityLogger->log('application.docker_resources_removed', $application, [
            'name' => $application->name,
            'removed' => $removed,
            // "Not deleted" is the half people look for when a volume is still on the server.
            'kept' => $kept,
        ]);

        return ['removed' => $removed, 'kept' => $kept];
    }
}
