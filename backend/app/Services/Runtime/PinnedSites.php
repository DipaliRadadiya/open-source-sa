<?php

namespace App\Services\Runtime;

use App\Models\Application;
use Illuminate\Support\Collection;

/**
 * Which sites pin which runtime version.
 *
 * Names, not just a count: "3 sites" does not tell you whether removing a
 * version breaks the staging box or the shop. But names are capped in list
 * responses — a server with eighty sites on one PHP version would otherwise
 * put eighty strings into a payload the screen loads on every visit. The
 * count alongside them is always the true total, so a truncated list never
 * reads as the whole story.
 *
 * The refusal message when you try to remove a version still names every one.
 * That is a single-item response where completeness is the point.
 */
class PinnedSites
{
    /**
     * Names per version, capped, keyed by version.
     *
     * @return Collection<string, array{count: int, names: array<int, string>, truncated: bool}>
     */
    public function summary(string $column): Collection
    {
        $limit = (int) config('server.runtimes.pinned_sites_shown', 5);

        return Application::query()
            ->whereNotNull($column)
            ->orderBy('name')
            ->get([$column.' as pinned_version', 'name'])
            ->groupBy('pinned_version')
            ->map(fn (Collection $apps) => [
                'count' => $apps->count(),
                'names' => $apps->take($limit)->pluck('name')->values()->all(),
                'truncated' => $apps->count() > $limit,
            ]);
    }

    /**
     * Every name for one version, uncapped — for the refusal message.
     *
     * @return array<int, string>
     */
    public function allFor(string $column, string $version): array
    {
        return Application::query()
            ->where($column, $version)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
