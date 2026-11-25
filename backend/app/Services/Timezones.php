<?php

namespace App\Services;

use App\Services\Server\ServerOps;
use DateTime;
use DateTimeZone;
use Throwable;

/**
 * The timezones this server accepts, grouped for a picker.
 *
 * Read from `timedatectl list-timezones`, because the OS is what accepts or rejects the value in
 * `timedatectl set-timezone`. Never use PHP's default `DateTimeZone::listIdentifiers()`: it omits
 * the backward-compatibility group, including `Etc/UTC`, which a fresh Debian or Ubuntu box uses.
 * Offsets and labels are still computed with PHP.
 *
 * Falls back to PHP's full list (with backward-compatible names) where timedatectl is absent,
 * such as a container without systemd.
 */
class Timezones
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Every accepted zone, grouped by region.
     *
     * @return array<int, array{region: string, zones: array<int, array{value: string, label: string, offset: string, offset_minutes: int}>}>
     */
    public function grouped(bool $phpOnly = false): array
    {
        // PHP's date.timezone knows fewer names than the OS (`US/Eastern` is in timedatectl, PHP
        // refuses it). The PHP settings picker asks for the ones both know.
        $identifiers = $phpOnly
            ? array_values(array_intersect($this->identifiers(), DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC)))
            : $this->identifiers();

        return collect($identifiers)
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
     * The flat set of accepted values, what validation checks against, so the picker and the
     * validator cannot disagree.
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
            // No systemd. ALL_WITH_BC keeps the backward-compatible names the OS uses.
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
            // A name the OS knows and PHP does not: skipped, as its offset cannot be shown.
            return null;
        }

        // The offset right now, so it stays right across DST.
        $minutes = intdiv($zone->getOffset(new DateTime('now', $zone)), 60);

        $parts = explode('/', $identifier);

        return [
            // Single-segment identifiers (UTC) are their own region.
            'region' => $parts[0],
            'value' => $identifier,
            // The city, not the identifier: it is what people scan for.
            'label' => str_replace('_', ' ', end($parts)),
            'offset' => sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60),
            // So the frontend can sort or filter without parsing the string.
            'offset_minutes' => $minutes,
        ];
    }
}
