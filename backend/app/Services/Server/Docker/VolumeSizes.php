<?php

namespace App\Services\Server\Docker;

use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * How much disk a container site's volumes actually hold.
 *
 * **Why the panel needed this at all.** A site's size is `du -sb` on its document
 * root, run as the site's own user. For a container site that root holds a compose
 * file and nothing else — measured on the test box, a Mattermost install reported
 * **6,468 bytes** while its six volumes held about **284 MB**, one of them 164 MB
 * of plugins. The number was not slightly wrong; it was four orders of magnitude
 * out, and it is the number the sites list sorts by.
 *
 * **Not `docker system df -v`**, which is where the Docker page gets its per-volume
 * figures. That reports `Size` as a rendered string — `164.2MB` — and turning it
 * back into bytes means the panel guessing at what three significant digits stood
 * for. Fine to display, useless to add up. `du -sb` on the mountpoint gives the
 * real figure, and this runs inside the already-queued, already-debounced size job,
 * so no request pays for the walk.
 *
 * **Run as root, unlike every other size measurement here.** `/var/lib/docker/
 * volumes` is `root:root` at 0700, so the site user cannot read its own data —
 * which is the point of a volume. The cost is an inode walk over data the panel
 * does not otherwise touch, which is why nothing calls this synchronously.
 */
class VolumeSizes
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * The total bytes held by every volume belonging to this site.
     *
     * Zero when the site has none, which is a real answer rather than a failure:
     * a container site with no volumes keeps nothing across a rebuild.
     */
    public function forApplication(Application $application): int
    {
        $mountpoints = array_values($this->discover($application));

        if ($mountpoints === []) {
            return 0;
        }

        // One `du` for all of them rather than one per volume. A site with six
        // volumes is six process spawns and six sudo round-trips otherwise, and
        // `du -sb a b c` prints one line each in a single walk.
        $result = $this->serverOps->run(
            array_merge(['du', '-sb'], $mountpoints),
            ['feature' => 'application', 'op' => 'docker_volume_size', 'application' => $application->id],
            timeout: 120,
        );

        // Deliberately tolerant, for the same reason `FileBrowser::measure()` is:
        // `du` exits 1 when it could not read *some* entries and still prints a
        // correct total for the rest. A slightly low figure beats none. A volume
        // that has vanished between the listing and the walk is exactly this case.
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
     *  - **`volume_mounts`** is what somebody attached on the Container screen. It
     *    can name a volume the panel did not create and does not prefix.
     *  - **The `sv-app-<id>_` prefix** covers everything the panel made for this
     *    site — every one-click app's volumes, and the ones a pasted compose file
     *    declared — none of which appear in `volume_mounts` at all.
     *
     * Asked of `docker volume ls` with a filter rather than `inspect` per name: one
     * call, and a name that no longer exists simply does not come back instead of
     * failing the whole measurement.
     *
     * Returns **name => mountpoint**, not a bare list. The name is what a backup
     * records and what a restore recreates; the mountpoint is what gets read.
     * Measuring size only ever needed the values, which is why this used to
     * return one column.
     *
     * @return array<string, string>
     */
    public function discover(Application $application): array
    {
        $result = $this->serverOps->run(
            ['docker', 'volume', 'ls', '--format', '{{.Name}}\t{{.Mountpoint}}'],
            ['feature' => 'application', 'op' => 'docker_volume_list', 'application' => $application->id],
            timeout: 30,
        );

        if (! $result->answered) {
            return [];
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

            // The prefix match is `str_starts_with` and not the regex the Docker
            // page uses, because here a false positive is the danger rather than a
            // missed row: site 2's volumes must never be counted against site 20,
            // and `sv-app-2_` is a prefix of nothing belonging to 20.
            if (in_array($name, $attached, true) || str_starts_with($name, $prefix)) {
                $discovered[$name] = $mountpoint;
            }
        }

        // Keyed by name, so duplicates collapse on their own — `array_unique`
        // was only ever guarding against the same volume arriving from both
        // sources, which a map does for free.
        return $discovered;
    }
}
