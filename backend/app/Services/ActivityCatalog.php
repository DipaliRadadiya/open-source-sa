<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Every event the panel can record, as `type.action` keys.
 *
 * Sourced from lang/activity.php — the single place each event's sentence
 * lives — minus the retired ones: their sentences stay so old rows still
 * read properly, but a feature that is gone can never produce a new row,
 * and an option that always returns nothing is a broken filter.
 */
class ActivityCatalog
{
    /**
     * @param  array<int, string>|null  $types  Limit to these types.
     * @return Collection<int, string>
     */
    public function keys(?array $types = null): Collection
    {
        return collect(Lang::get('activity', [], 'en'))->keys()
            ->reject(fn (string $key) => in_array($key, (array) config('activity.retired', []), true))
            ->when($types !== null, fn (Collection $keys) => $keys->filter(
                fn (string $key) => in_array(Str::before($key, '.'), $types, true),
            ))
            ->values();
    }

    /**
     * The `types` / `actions` shape the filter dropdowns read: actions grouped
     * per type for dependent dropdowns, plus an `all` deduped list for the
     * initial "any type" view (no frontend merge).
     *
     * @param  Collection<int, string>  $keys
     * @return array{types: array<int, string>, actions: array<string, array<int, string>>}
     */
    public function shape(Collection $keys): array
    {
        $types = $keys->map(fn (string $key) => Str::before($key, '.'))->unique()->sort()->values();

        $perType = $keys
            ->groupBy(fn (string $key) => Str::before($key, '.'))
            ->map(fn ($group) => $group->map(fn (string $key) => Str::after($key, '.'))->unique()->sort()->values()->all());

        $all = $keys->map(fn (string $key) => Str::after($key, '.'))->unique()->sort()->values()->all();

        return [
            'types' => $types->all(),
            'actions' => ['all' => $all] + $perType->all(),
        ];
    }
}
