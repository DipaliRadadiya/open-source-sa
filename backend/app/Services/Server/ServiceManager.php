<?php

namespace App\Services\Server;

use App\Contracts\PhpStack;

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

    public function __construct(
        private ServerOps $serverOps,
        private ServiceUsage $usage,
        private ConfigTester $tester,
        private LogManager $logs,
        private PhpStack $stack,
    ) {}

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
            'testable' => $this->tester->testable($service['key']),
            // A stopped unit has no resources to report — see ServiceUsage.
            'usage' => $state['status'] === 'active' ? $this->usage->build($service['unit'], $state['properties']) : null,
            // This service's log files, as keys into the existing Logs feature
            // rather than a second way to read a log. Only sources that exist
            // on the box appear, so the button is never a dead end.
            'log_keys' => $this->logKeys($service['key']),
        ];
    }

    /**
     * The log sources belonging to a service, as `logs` registry keys.
     *
     * Returned so the frontend can open the log viewer straight from a service
     * row. Reading them still goes through the Logs feature and its own
     * permission — this exposes the association, not the content.
     *
     * @return array<int, string>
     */
    public function logKeys(string $key): array
    {
        // php8.4-fpm → php8.4_fpm, the key LogManager derives per version.
        $candidates = ($version = $this->stack->versionForService($key)) !== null
            ? ["php{$version}_fpm"]
            : (array) config("server.service_logs.{$key}", []);

        return array_values(array_filter(
            $candidates,
            fn (string $candidate) => ($source = $this->logs->find($candidate)) !== null
                && $this->logs->describe($source) !== null,
        ));
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
        $services = [];

        foreach ($this->stack->versions() as $version) {
            $unit = $this->stack->serviceName($version);

            // LSPHP has no per-version unit — its processes belong to the web
            // server. A stack with nothing to start or stop contributes no
            // rows rather than rows that cannot be acted on.
            if ($unit === null) {
                continue;
            }

            $services[] = ['key' => $unit, 'unit' => $unit, 'label' => "PHP {$version} FPM"];
        }

        return $services;
    }

    /**
     * State + usage counters in ONE systemctl call. The usage properties come
     * from systemd's own cgroup accounting, which is why the whole service
     * tree (a php-fpm master and all its workers) is counted correctly, and
     * why adding them costs nothing — the call was already being made.
     *
     * @return array{installed: bool, status: string, enabled: bool, properties: array<string, string|null>}
     */
    private function inspect(string $unit): array
    {
        $output = $this->serverOps->run(
            ['systemctl', 'show', $unit, '--property=LoadState,ActiveState,UnitFileState,MemoryCurrent,CPUUsageNSec,TasksCurrent'],
            ['feature' => 'service', 'op' => 'inspect', 'unit' => $unit],
        )->output();

        return [
            'installed' => $this->property($output, 'LoadState') === 'loaded',
            'status' => $this->property($output, 'ActiveState') ?: 'inactive',
            'enabled' => $this->property($output, 'UnitFileState') === 'enabled',
            'properties' => [
                'MemoryCurrent' => $this->property($output, 'MemoryCurrent'),
                'CPUUsageNSec' => $this->property($output, 'CPUUsageNSec'),
                'TasksCurrent' => $this->property($output, 'TasksCurrent'),
            ],
        ];
    }

    private function property(string $output, string $key): ?string
    {
        return preg_match('/^'.$key.'=(.*)$/m', $output, $matches) ? trim($matches[1]) : null;
    }
}
