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
}
