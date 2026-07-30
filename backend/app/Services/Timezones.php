<?php

namespace App\Services;

use DateTime;
use DateTimeZone;

/**
 * The timezones this panel accepts, grouped for a picker.
 *
 * Read from `DateTimeZone::listIdentifiers()` — the same source
 * GeneralSettingsRequest validates against — and deliberately not from
 * `timedatectl list-timezones`, which reports 497 zones to PHP's 419. The
 * extra 78 are deprecated aliases (`US/Eastern`, `Etc/GMT+5`); offering one in
 * the picker would produce a value the API then refuses, with nothing on
 * screen explaining why. One source, or the two drift.
 */
class Timezones
{
    /**
     * Every accepted zone, grouped by region.
     *
     * @return array<int, array{region: string, zones: array<int, array{value: string, label: string, offset: string, offset_minutes: int}>}>
     */
    public function grouped(): array
    {
        return collect($this->identifiers())
            ->map(fn (string $identifier) => $this->describe($identifier))
            ->groupBy('region')
            ->map(fn ($zones, $region) => [
                'region' => $region,
                'zones' => $zones
                    ->map(fn (array $zone) => collect($zone)->except('region')->all())
                    ->sortBy('label', SORT_NATURAL)
                    ->values()
                    ->all(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function identifiers(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * @return array{region: string, value: string, label: string, offset: string, offset_minutes: int}
     */
    private function describe(string $identifier): array
    {
        $zone = new DateTimeZone($identifier);

        // The offset *right now*, so it is right across DST rather than
        // frozen at whatever it was when someone last deployed. Computing all
        // 419 costs under 10ms, which is cheaper than being wrong for the
        // hour after a transition.
        $minutes = intdiv($zone->getOffset(new DateTime('now', $zone)), 60);

        $parts = explode('/', $identifier);

        return [
            // Single-segment identifiers (UTC) are their own region rather
            // than being dropped or hidden under something invented.
            'region' => $parts[0],
            'value' => $identifier,
            // The city, not the identifier: it is what people scan for.
            'label' => str_replace('_', ' ', end($parts)),
            'offset' => sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60),
            // So the frontend can sort or filter by offset without parsing
            // the string back out.
            'offset_minutes' => $minutes,
        ];
    }
}
