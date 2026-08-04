<?php

namespace App\Services\Server\Php;

use App\Models\Application;
use App\Models\ApplicationPhpSettings;

/**
 * What every site's PHP settings add up to, against what the server has.
 *
 * The number none of the panels I looked at will show you. All of them let you
 * set 50 workers × 512M on a 2 GB box; you find out when the OOM killer takes
 * a *different* site down at three in the morning and nothing connects the two
 * events.
 *
 * A worst case, not a prediction — workers rarely all sit at their limit. But
 * the worst case is what the kernel reacts to, and it is the only number that
 * can be computed rather than guessed.
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

        $settings = ApplicationPhpSettings::query()
            ->with('application')
            ->when($excluding !== null, fn ($query) => $query->where('application_id', '!=', $excluding->id))
            ->get();

        foreach ($settings as $row) {
            // Only isolated sites have a pool of their own; the rest share the
            // server pool, whose memory is already accounted for by the OS.
            if ($row->application?->isolated_at === null) {
                continue;
            }

            $committed += $row->memoryCeilingBytes();
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
     * The budget as it would be with these settings applied to this site —
     * what the screen shows *before* someone presses save.
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
     * Total RAM, read from /proc rather than stored.
     *
     * A VPS can be resized under you, and a cached figure would then be a
     * budget against a machine that no longer exists.
     */
    private function totalMemoryBytes(): int
    {
        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false || preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $match) !== 1) {
            return 0;
        }

        return ((int) $match[1]) * 1024;
    }
}
