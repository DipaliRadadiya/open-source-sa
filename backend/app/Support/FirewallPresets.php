<?php

namespace App\Support;

/**
 * Common-service firewall presets for the frontend dropdown — click "Allow
 * HTTPS" instead of typing port numbers. `custom` has a null port (the UI
 * reveals the raw port/range fields). Labels are localized at read time.
 */
class FirewallPresets
{
    /**
     * @var array<string, array{port: int|null, protocol: string}>
     */
    public const PRESETS = [
        'ssh' => ['port' => 22, 'protocol' => 'tcp'],
        'http' => ['port' => 80, 'protocol' => 'tcp'],
        'https' => ['port' => 443, 'protocol' => 'tcp'],
        'mysql' => ['port' => 3306, 'protocol' => 'tcp'],
        'postgresql' => ['port' => 5432, 'protocol' => 'tcp'],
        'redis' => ['port' => 6379, 'protocol' => 'tcp'],
        'ftp' => ['port' => 21, 'protocol' => 'tcp'],
        'smtp' => ['port' => 25, 'protocol' => 'tcp'],
        'dns' => ['port' => 53, 'protocol' => 'udp'],
        'custom' => ['port' => null, 'protocol' => 'tcp'],
    ];

    /**
     * Presets with localized labels, in display order.
     *
     * @return array<int, array{key: string, label: string, port: int|null, protocol: string}>
     */
    public static function all(): array
    {
        $presets = [];

        foreach (self::PRESETS as $key => $preset) {
            $presets[] = [
                'key' => $key,
                'label' => __('firewall.presets.'.$key),
                'port' => $preset['port'],
                'protocol' => $preset['protocol'],
            ];
        }

        return $presets;
    }

    /**
     * The port a service name refers to, or null if the term names no preset.
     *
     * Matched against the preset **key**, not the localized label, and that is
     * a measurement rather than a shortcut: every service name in each
     * locale's `firewall.php` is the same ASCII string in all eight locales —
     * only `custom` is translated. So a key lookup already answers for every
     * language, and matching labels would add a translation pass that could
     * only ever return the same rows.
     *
     * `custom` is excluded by having no port of its own; it is the UI's
     * "let me type one" entry, not a service.
     */
    public static function portFor(string $term): ?int
    {
        $key = strtolower(trim($term));

        return self::PRESETS[$key]['port'] ?? null;
    }
}
