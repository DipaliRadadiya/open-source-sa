<?php

namespace App\Services\Server\Docker;

use App\Models\Application;
use App\Services\Server\Applications\ContainerSupervisor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The network a container site was created on, once it has been moved to
 * another one (BUG-5).
 *
 * A generated compose file that names no network gets one from Compose,
 * `<project>_default`. Moving the site to a chosen network rewrites the file to
 * name only that one, and `compose up` stops using the default without removing
 * it — `--remove-orphans` removes containers, never networks. So the original
 * network stayed on the server for good: found in Docker QA on a real box,
 * `sv-app-110_default` outlived its site and was still listed against it.
 *
 * Asked in two places — after a network change is applied, and when the site is
 * deleted with its Docker resources — and it is the same decision both times, so
 * it lives here once.
 *
 * Removed only when it is provably this site's and provably unused: Compose's
 * own labels say it was created as this project's default network (the name
 * alone could be anybody's), no other site names it, and no container outside
 * this project is attached. Anything short of that is kept, and why is recorded.
 */
class OriginalNetwork
{
    public function __construct(
        private DockerResources $docker,
        private ContainerSupervisor $containers,
    ) {}

    /**
     * The original network's name if it may be removed, or null.
     *
     * Only when the site is on ANOTHER network. On its own default, `compose
     * down` removes it as part of a delete, and planning it here as well would
     * only produce a "remove failed" for a network that is already gone.
     *
     * @param  Collection<int, array<string, mixed>>  $networks  {@see DockerResources::networks()}
     * @param  list<string>  $kept  appended to with the reason, when it is kept.
     */
    public function find(Application $application, Collection $networks, array &$kept): ?string
    {
        $project = $this->containers->project($application);
        $name = $project.'_default';
        $current = (string) ($application->docker_network ?? '');

        if ($current === '' || $current === $name) {
            return null;
        }

        $row = $networks->firstWhere('name', $name);

        // Not there: nothing was left behind, and nothing needs saying.
        if ($row === null) {
            return null;
        }

        $owner = $this->docker->composeNetwork($name);

        if ($owner === null || $owner['project'] !== $project || $owner['network'] !== 'default') {
            $kept[] = "{$name}:not-created-by-this-site";

            return null;
        }

        $others = collect($row['sites'] ?? [])
            ->reject(fn (array $site): bool => (int) $site['id'] === (int) $application->id);

        if ($others->isNotEmpty()) {
            $kept[] = "{$name}:joined-by-".$others->pluck('name')->implode(',');

            return null;
        }

        $foreign = collect($row['containers'] ?? [])
            ->reject(fn (array $container): bool => str_starts_with((string) $container['name'], $project.'-'));

        if ($foreign->isNotEmpty()) {
            $kept[] = "{$name}:attached-".$foreign->pluck('name')->implode(',');

            return null;
        }

        return $name;
    }

    /**
     * Remove it now, after a network change has been applied.
     *
     * Never fatal. The change the user asked for has already happened and the
     * site is running on its new network; a leftover network is tidiness, and
     * failing the save over it would report a working change as a broken one.
     * `docker network rm` without `--force` refuses a network that still has an
     * endpoint, so Docker itself is the last guard.
     *
     * @return array{removed: ?string, kept: list<string>}
     */
    public function release(Application $application): array
    {
        $kept = [];
        $name = $this->find($application, collect($this->docker->networks()), $kept);

        if ($name !== null && $this->docker->removeNetwork($name)->failed()) {
            $kept[] = "{$name}:remove-failed";
            $name = null;
        }

        if ($kept !== []) {
            Log::channel('server-ops')->info('docker.original_network_kept', [
                'application' => $application->id,
                'kept' => $kept,
            ]);
        }

        return ['removed' => $name, 'kept' => $kept];
    }
}
