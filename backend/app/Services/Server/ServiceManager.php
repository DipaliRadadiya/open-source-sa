<?php

namespace App\Services\Server;

/**
 * Manages system services via systemctl. No DB — state is read live from
 * systemd (detect-don't-trust). The catalog is our supported type sets
 * (config) plus php-fpm units detected from `php_dir`; only installed units
 * surface. Protected units (panel's own web server + php-fpm) can't be
 * stopped/disabled. All ops go through ServerOps (array args, no injection).
 */
class ServiceManager
{
    /**
     * @var array<int, string>
     */
    public const ACTIONS = ['start', 'stop', 'restart', 'reload', 'enable', 'disable'];

    public function __construct(private ServerOps $serverOps) {}

    /**
     * All managed + installed services, with live status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return array_values(array_filter(array_map(
            fn (array $service) => $this->describe($service),
            $this->catalog(),
        )));
    }

    /**
     * The catalog entry for a key, or null if it's not a managed service.
     *
     * @return array{key: string, unit: string, label: string}|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->catalog() as $service) {
            if ($service['key'] === $key) {
                return $service;
            }
        }

        return null;
    }

    /**
     * The display shape for one catalog entry, or null when it isn't installed.
     *
     * @param  array{key: string, unit: string, label: string}  $service
     * @return array<string, mixed>|null
     */
    public function describe(array $service): ?array
    {
        $state = $this->inspect($service['unit']);

        if (! $state['installed']) {
            return null;
        }

        return [
            'key' => $service['key'],
            'label' => $service['label'],
            'unit' => $service['unit'],
            'status' => $state['status'],
            'enabled' => $state['enabled'],
            'protected' => $this->isProtected($service['unit']),
            'actions' => $this->allowedActions($service['unit']),
            // Whether this service can validate its own configuration, so the
            // UI only offers the button where it means something.
            'testable' => app(ConfigTester::class)->testable($service['key']),
        ];
    }

    public function run(string $unit, string $action): ServerOpsResult
    {
        return $this->serverOps->run(
            ['systemctl', $action, $unit],
            ['feature' => 'service', 'op' => $action, 'unit' => $unit],
        );
    }

    public function isProtected(string $unit): bool
    {
        return in_array($unit, config('server.protected_services', []), true);
    }

    /**
     * @return array<int, string>
     */
    public function allowedActions(string $unit): array
    {
        // Protected units keep restart/reload/enable but can't be stopped or
        // disabled (that would take the panel offline).
        return $this->isProtected($unit)
            ? ['restart', 'reload', 'enable']
            : self::ACTIONS;
    }

    /**
     * Config services + php-fpm units detected from php_dir.
     *
     * @return array<int, array{key: string, unit: string, label: string}>
     */
    private function catalog(): array
    {
        return array_merge(config('server.services', []), $this->phpFpmServices());
    }

    /**
     * @return array<int, array{key: string, unit: string, label: string}>
     */
    private function phpFpmServices(): array
    {
        $dir = (string) config('server.php_dir', '/etc/php');

        if (! is_dir($dir)) {
            return [];
        }

        $services = [];
        foreach (glob($dir.'/*/fpm', GLOB_ONLYDIR) ?: [] as $fpm) {
            $version = basename(dirname($fpm));
            $services[] = ['key' => "php{$version}-fpm", 'unit' => "php{$version}-fpm", 'label' => "PHP {$version} FPM"];
        }

        return $services;
    }

    /**
     * @return array{installed: bool, status: string, enabled: bool}
     */
    private function inspect(string $unit): array
    {
        $output = $this->serverOps->run(
            ['systemctl', 'show', $unit, '--property=LoadState,ActiveState,UnitFileState'],
            ['feature' => 'service', 'op' => 'inspect', 'unit' => $unit],
        )->output();

        return [
            'installed' => $this->property($output, 'LoadState') === 'loaded',
            'status' => $this->property($output, 'ActiveState') ?: 'inactive',
            'enabled' => $this->property($output, 'UnitFileState') === 'enabled',
        ];
    }

    private function property(string $output, string $key): ?string
    {
        return preg_match('/^'.$key.'=(.*)$/m', $output, $matches) ? trim($matches[1]) : null;
    }
}
