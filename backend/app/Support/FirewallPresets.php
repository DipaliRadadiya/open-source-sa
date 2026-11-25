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
     * @param  bool  $mariadb  MariaDB is installed here, and names the 3306 preset
     * @return array<int, array{key: string, label: string, port: int|null, protocol: string}>
     */
    public static function all(bool $mariadb = false): array
    {
        $presets = [];

        foreach (self::PRESETS as $key => $preset) {
            $presets[] = [
                'key' => $key,
                // The 3306 preset is named for the engine on this server; the key stays `mysql`
                // because the rule is the same either way.
                'label' => __('firewall.presets.'.($key === 'mysql' && $mariadb ? 'mariadb' : $key)),
                'port' => $preset['port'],
                'protocol' => $preset['protocol'],
            ];
        }

        return $presets;
    }

    /**
     * The port a service name refers to, or null if the term names no preset.
     *
     * Matched against the preset key, not the localized label: every service name is the same
     * ASCII string in all locales (only `custom` is translated).
     *
     * `custom` is excluded by having no port of its own.
     */
    public static function portFor(string $term): ?int
    {
        $key = strtolower(trim($term));

        return self::PRESETS[self::ALIASES[$key] ?? $key]['port'] ?? null;
    }

    /**
     * Other names a preset answers to in a rule search: MariaDB and MySQL both find the 3306 rules.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'mariadb' => 'mysql',
    ];
}
