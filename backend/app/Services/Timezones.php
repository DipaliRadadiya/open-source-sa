<?php

namespace App\Services;

use App\Services\Server\ServerOps;
use DateTime;
use DateTimeZone;
use Throwable;

/**
 * The timezones this server accepts, grouped for a picker.
 *
 * Read from `timedatectl list-timezones`, because that is the authority: the
 * value ends up as an argument to `timedatectl set-timezone`, so the OS is
 * what accepts or rejects it. An earlier version of this used
 * `DateTimeZone::listIdentifiers()` instead, on the reasoning that it matched
 * what the validator checked — but the validator was the thing that was
 * wrong. PHP's default list omits the backward-compatibility group, which is
 * 78 zones including `Etc/UTC`, and `Etc/UTC` is what a fresh Debian or
 * Ubuntu box is actually set to. The result was a settings form that showed
 * the server's real timezone, refused to accept it back, and could not be
 * saved at all without changing it.
 *
 * Every zone the OS offers is one PHP can construct — verified, 497 of 497 —
 * so offsets and labels are still computed with PHP.
 *
 * Falls back to PHP's full list (including backward-compatible names) where
 * timedatectl is absent, such as a container without systemd.
 */
class Timezones
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Every accepted zone, grouped by region.
     *
     * @return array<int, array{region: string, zones: array<int, array{value: string, label: string, offset: string, offset_minutes: int}>}>
     */
    public function grouped(): array
    {
        return collect($this->identifiers())
            ->map(fn (string $identifier) => $this->describe($identifier))
            ->filter()
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
     * The flat set of accepted values — what validation checks against, so
     * that the picker and the validator cannot disagree.
     *
     * @return array<int, string>
     */
    public function identifiers(): array
    {
        $result = $this->serverOps->run(
            ['timedatectl', 'list-timezones'],
            ['feature' => 'timezone', 'op' => 'list'],
        );

        $zones = collect(preg_split('/\r?\n/', trim($result->output())) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values();

        if (! $result->ok || $zones->isEmpty()) {
            // No systemd. ALL_WITH_BC rather than the default set, so the
            // backward-compatible names the OS uses are still accepted.
            return DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        }

        return $zones->all();
    }

    public function accepts(string $identifier): bool
    {
        return in_array($identifier, $this->identifiers(), true);
    }

    /**
     * @return array{region: string, value: string, label: string, offset: string, offset_minutes: int}|null
     */
    private function describe(string $identifier): ?array
    {
        try {
            $zone = new DateTimeZone($identifier);
        } catch (Throwable) {
            // A name the OS knows and PHP does not. None exist today; skipped
            // rather than offered, because we could not show its offset and
            // the picker would be lying about it.
            return null;
        }

        // The offset right now, so it is right across DST rather than frozen
        // at whatever it was when someone last deployed. All of them cost
        // under 10ms, which is cheaper than being wrong for the hour after a
        // transition.
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
