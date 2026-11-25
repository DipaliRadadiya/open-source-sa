<?php

namespace App\Services\Server\Php;

use App\Models\Application;
use App\Models\ApplicationPhpSettings;

/**
 * What every site's PHP settings add up to, against what the server has.
 *
 * Without it, 50 workers × 512M on a 2 GB box goes unnoticed until the OOM killer takes a
 * *different* site down. A worst case, not a prediction, but it is what the kernel reacts to
 * and the only number that can be computed rather than guessed.
 */
class MemoryBudget
{
    /**
     * @return array{total: int, committed: int, available: int, over_committed: bool, sites: int}
     */
    public function forServer(?Application $excluding = null): array
    {
        $total = $this->totalMemoryBytes();
        $committed = 0;
        $sites = 0;

        // Every site with a pool of its own, saved settings or not: pools still on the defaults
        // (20 workers × 256M = 5 GB) are committed memory too.
        $applications = Application::query()
            ->whereNotNull('isolated_at')
            ->with('phpSettings')
            ->when($excluding !== null, fn ($query) => $query->whereKeyNot($excluding->id))
            ->get();

        foreach ($applications as $application) {
            $settings = $application->phpSettings ?? new ApplicationPhpSettings;

            $committed += $settings->memoryCeilingBytes();
            $sites++;
        }

        return [
            'total' => $total,
            'committed' => $committed,
            'available' => max(0, $total - $committed),
            'over_committed' => $total > 0 && $committed > $total,
            'sites' => $sites,
        ];
    }

    /**
     * The budget as it would be with these settings applied to this site, shown before save.
     *
     * @return array{total: int, committed: int, available: int, over_committed: bool, sites: int, this_site: int}
     */
    public function withProposed(Application $application, ApplicationPhpSettings $proposed): array
    {
        $others = $this->forServer(excluding: $application);
        $mine = $proposed->memoryCeilingBytes();
        $committed = $others['committed'] + $mine;

        return [
            'total' => $others['total'],
            'committed' => $committed,
            'available' => max(0, $others['total'] - $committed),
            'over_committed' => $others['total'] > 0 && $committed > $others['total'],
            'sites' => $others['sites'] + 1,
            'this_site' => $mine,
        ];
    }

    /**
     * Total RAM, read from /proc rather than stored: a resized VPS would leave a cached figure
     * stale. 0 when unknown.
     */
    public function totalMemoryBytes(): int
    {
        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false || preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $match) !== 1) {
            return 0;
        }

        return ((int) $match[1]) * 1024;
    }
}
