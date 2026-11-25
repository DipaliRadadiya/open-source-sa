<?php

namespace App\Services\Server\Docker;

use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * How much disk a container site's volumes actually hold.
 *
 * A site's size is `du -sb` on its document root, which for a container site holds only a compose
 * file, so its volumes' data would otherwise not count towards the size the sites list sorts by.
 *
 * Not `docker system df -v`: it reports `Size` as a rendered string (`164.2MB`), which cannot be
 * summed reliably. `du -sb` on the mountpoint gives real bytes, and runs inside the already-queued
 * size job so no request pays for the walk.
 *
 * Runs as root, unlike the other size measurements: `/var/lib/docker/volumes` is `root:root` 0700.
 * The inode walk is why nothing calls this synchronously.
 */
class VolumeSizes
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * The total bytes held by every volume belonging to this site.
     *
     * Zero when the site has none, which is a real answer, not a failure.
     */
    public function forApplication(Application $application): int
    {
        $mountpoints = array_values($this->discover($application));

        if ($mountpoints === []) {
            return 0;
        }

        // One `du` for all volumes: it prints one line each, saving a spawn and sudo round-trip per volume.
        $result = $this->serverOps->run(
            array_merge(['du', '-sb'], $mountpoints),
            ['feature' => 'application', 'op' => 'docker_volume_size', 'application' => $application->id],
            timeout: 120,
        );

        // Tolerant, like `FileBrowser::measure()`: `du` exits 1 when it could not read some entries
        // (e.g. a volume that vanished since the listing) but still prints a total for the rest.
        $total = 0;

        foreach (explode("\n", trim($result->output())) as $line) {
            [$bytes] = explode("\t", trim($line), 2);

            $total += max(0, (int) $bytes);
        }

        return $total;
    }

    /**
     * Where on the host each of this site's volumes lives.
     *
     * Two sources, unioned, because neither is complete on its own:
     *
     *  - **`volume_mounts`**: volumes attached on the Container screen, which the panel may not
     *    have created or prefixed.
     *  - **The `sv-app-<id>_` prefix**: everything the panel made for this site (one-click apps,
     *    pasted compose files), none of which appear in `volume_mounts`.
     *
     * Uses one `docker volume ls` rather than `inspect` per name, so a vanished volume does not
     * fail the whole measurement. Returns name => mountpoint: a backup records the name, and the
     * mountpoint is what gets read.
     *
     * @return array<string, string>
     */
    public function discover(Application $application): array
    {
        return $this->find($application) ?? [];
    }

    /**
     * Docker answered, and none of the volumes it listed belong to this site.
     *
     * False when Docker could not be asked — see {@see self::find()}.
     */
    public function hasNone(Application $application): bool
    {
        return $this->find($application) === [];
    }

    /**
     * {@see self::discover()}, but null when Docker did not answer.
     *
     * The two empties differ: refusing a backup as "no volumes" because the daemon was briefly
     * unreachable would be false, so the refusals ask this and act only on `[]`.
     *
     * @return array<string, string>|null
     */
    public function find(Application $application): ?array
    {
        $result = $this->serverOps->run(
            ['docker', 'volume', 'ls', '--format', '{{.Name}}\t{{.Mountpoint}}'],
            ['feature' => 'application', 'op' => 'docker_volume_list', 'application' => $application->id],
            timeout: 30,
        );

        if (! $result->answered) {
            return null;
        }

        $attached = collect((array) ($application->volume_mounts ?? []))
            ->filter(fn ($mount): bool => is_array($mount) && ($mount['volume'] ?? '') !== '')
            ->map(fn (array $mount): string => (string) $mount['volume'])
            ->unique()
            ->all();

        $prefix = 'sv-app-'.$application->id.'_';
        $discovered = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            $parts = explode("\t", trim($line), 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$name, $mountpoint] = [trim($parts[0]), trim($parts[1])];

            if ($name === '' || $mountpoint === '') {
                continue;
            }

            // `str_starts_with`, not the Docker page's regex: a false positive is the danger here
            // (site 2's volumes must never count against site 20).
            if (in_array($name, $attached, true) || str_starts_with($name, $prefix)) {
                $discovered[$name] = $mountpoint;
            }
        }

        // Keyed by name, so a volume found by both sources collapses.
        return $discovered;
    }
}
