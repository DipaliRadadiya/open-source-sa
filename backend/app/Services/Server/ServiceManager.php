<?php

namespace App\Services\Server;

use App\Contracts\PhpStack;
use App\Enums\InstallStatus;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Capabilities\ServerCapabilities;

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
        private ServerCapabilities $capabilities,
    ) {}

    /**
     * All managed + installed services, with live status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $rows = [];
        $unitIndexes = [];

        foreach ($this->catalog() as $service) {
            $state = $this->inspect($service['unit']);
            $row = $this->describeState($service, $state);

            if ($row === null) {
                continue;
            }

            // Packages may expose compatibility aliases. MariaDB, for example,
            // makes mysql.service an alias of mariadb.service, so both probes
            // return loaded even though there is only one daemon. Systemd's Id
            // is the canonical unit and is identical through every alias.
            $unitId = $state['installed'] ? $state['id'] : null;

            if ($unitId === null || ! isset($unitIndexes[$unitId])) {
                $rows[] = $row;

                if ($unitId !== null) {
                    $unitIndexes[$unitId] = array_key_last($rows);
                }

                continue;
            }

            // Prefer the catalog entry that names the canonical unit. This is
            // what turns mysql.service -> mariadb.service into one MariaDB row
            // regardless of catalog order. If no configured entry is canonical,
            // keeping the first alias is deterministic and still avoids duplicates.
            if ($this->systemdId($service['unit']) === $unitId) {
                $rows[$unitIndexes[$unitId]] = $row;
            }
        }

        return array_values($rows);
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
        return $this->describeState($service, $this->inspect($service['unit']));
    }

    /**
     * @param  array{key: string, unit: string, label: string}  $service
     * @param  array{installed: bool, id: ?string, status: string, enabled: bool, can_reload: bool, properties: array<string, string|null>}  $state
     * @return array<string, mixed>|null
     */
    private function describeState(array $service, array $state): ?array
    {
        if (! $state['installed']) {
            // Not on the box — but it may be on its way, or have tried and
            // failed. Either is something the user asked for and should be able
            // to see here; only a service nobody has ever asked for stays
            // absent.
            return $this->describePendingInstall($service);
        }

        return [
            'key' => $service['key'],
            'label' => $service['label'],
            'unit' => $service['unit'],
            // Always `installed` here: the unit exists, whatever it is doing.
            // A single field for the frontend to switch on, rather than
            // inferring the difference from a status string that means
            // something else.
            'state' => 'installed',
            'install_reason' => null,
            'install_message' => null,
            'retryable' => false,
            'status' => $state['status'],
            'enabled' => $state['enabled'],
            'protected' => $this->isProtected($service['unit']),
            'actions' => $this->allowedActions($service, $state['can_reload']),
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
     * A row for a service whose unit is absent but whose install is in progress
     * or has failed — or null when nobody has ever tried to install it.
     *
     * Without this the service simply vanishes from the list, which reads as
     * "the panel forgot" rather than "the install is still going" or "the
     * install failed". A failed engine is the case that matters: it is silent
     * everywhere the user is likely to look next.
     *
     * **Reads the same `runtime_installs` rows the setup page and
     * `GET /databases/engines` read.** Three readers of one source, not three
     * copies of one fact — the copies are what would let two screens disagree.
     *
     * The row is deliberately inert: no actions, no usage, not testable, no
     * logs. There is no unit to act on, and offering a Restart button for
     * something that does not exist is worse than offering nothing.
     *
     * @param  array{key: string, unit: string, label: string, install?: array{0: string, 1: string}}  $service
     * @return array<string, mixed>|null
     */
    private function describePendingInstall(array $service): ?array
    {
        if (! isset($service['install'])) {
            return null;
        }

        [$runtime, $version] = $service['install'];

        $install = app(InstallTracker::class)->current($runtime, $version);

        // No row at all, or one left at `ready` — a finished install deletes
        // its row, so `ready` here would be a leftover rather than a state
        // worth showing.
        if ($install === null || $install->status === InstallStatus::Ready) {
            return null;
        }

        $failed = $install->status === InstallStatus::Failed;

        return [
            'key' => $service['key'],
            'label' => $service['label'],
            'unit' => $service['unit'],
            'state' => $failed ? 'install_failed' : 'installing',
            'install_reason' => $install->reason,
            // The model's own sentence, so this row and the setup card cannot
            // word the same failure differently.
            'install_message' => $install->message(),
            'retryable' => $failed,
            /*
             * Systemd's vocabulary, not a new one — and the division of labour
             * that makes every row manageable the same way:
             *
             *   `status` is how this row is doing, in the three words the
             *           status badge already renders: active, inactive, failed.
             *   `state`  is what kind of row it is.
             *
             * A failed install is `failed` because that is what it is to the
             * person looking at it: broken, and red. An install in progress is
             * `inactive` because nothing is running yet. Returning
             * `install_failed` here instead put an unrecognised word through
             * the badge's deliberate fallback — a grey question mark printing
             * the raw string — which is the one presentation that says nothing.
             *
             * Nothing is lost by reusing the words: `state` carries the
             * distinction for anyone who needs it, so a client can tell a
             * service that crashed from one that never installed while both
             * still read as broken.
             *
             * Never null, either. The client types `status` as a string, and a
             * null would fail its parse and drop the whole response rather than
             * this one row.
             */
            'status' => $failed ? 'failed' : 'inactive',
            'enabled' => false,
            'protected' => false,
            'actions' => [],
            'testable' => false,
            'usage' => null,
            'log_keys' => [],
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
        return in_array($unit, $this->protectedUnits(), true);
    }

    /**
     * The panel's web server is determined by the recorded installation stack
     * (or brownfield capability detection), not by a static nginx assumption.
     *
     * @return array<int, string>
     */
    private function protectedUnits(): array
    {
        // Redis backs the panel's queues and cache. It may be optional for a
        // user app, but it is not optional for the panel itself.
        $units = [...(array) config('server.protected_services', []), 'redis-server'];
        $webServer = $this->capabilities->webServer();

        foreach ((array) config('server.services', []) as $service) {
            if (($service['key'] ?? null) === $webServer) {
                $units[] = $service['unit'];
                break;
            }
        }

        return array_values(array_unique($units));
    }

    /**
     * @return array<int, string>
     */
    public function allowedActions(array $service, bool $canReload): array
    {
        // Protected units keep restart/reload/enable but can't be stopped or
        // disabled (that would take the panel offline).
        $actions = $this->isProtected($service['unit'])
            ? ['restart', 'reload', 'enable']
            : self::ACTIONS;

        // systemd knows whether the unit implements reload. Do not offer a
        // button that can only fail, regardless of what kind of service it is.
        if (! $canReload) {
            $actions = array_values(array_diff($actions, ['reload']));
        }

        return $actions;
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
     * PHP-FPM rows: one per installed version, plus one per version the panel
     * is installing or failed to install.
     *
     * The second half is why this is not just a loop over installed versions.
     * These entries are generated rather than configured, so the `install` key
     * that gives every other service its in-progress row cannot be written into
     * the catalog for them — a version that never finished installing has no
     * unit and no config entry, and so could not appear at all however well
     * describe() handled it. It was the one kind of service that stayed
     * invisible exactly when the user most wanted to see it.
     *
     * `versions()` on the tracker filters out extension rows, so installing a
     * PHP *extension* cannot conjure a service row for the version it belongs
     * to. That is the tracker's own design doing the work rather than this
     * method remembering to.
     *
     * @return array<int, array{key: string, unit: string, label: string, install: array{0: string, 1: string}}>
     */
    private function phpFpmServices(): array
    {
        $pending = app(InstallTracker::class)
            ->versions('php')
            ->reject(fn ($install) => $install->status === InstallStatus::Ready)
            ->keys()
            ->all();

        // Installed first: an installed version whose stale tracker row somehow
        // survived should be described by its unit, and array_unique keeps the
        // first occurrence.
        $versions = array_unique([...$this->stack->versions(), ...$pending]);

        $services = [];

        foreach ($versions as $version) {
            $unit = $this->stack->serviceName($version);

            // LSPHP has no per-version unit — its processes belong to the web
            // server. A stack with nothing to start or stop contributes no
            // rows rather than rows that cannot be acted on. That holds for a
            // version being installed too: there would be nothing for the row
            // to become once it finished.
            if ($unit === null) {
                continue;
            }

            $services[] = [
                'key' => $unit,
                'unit' => $unit,
                'label' => "PHP {$version} FPM",
                'install' => ['php', $version],
            ];
        }

        return $services;
    }

    /**
     * State + usage counters in ONE systemctl call. The usage properties come
     * from systemd's own cgroup accounting, which is why the whole service
     * tree (a php-fpm master and all its workers) is counted correctly, and
     * why adding them costs nothing — the call was already being made.
     *
     * @return array{installed: bool, id: ?string, status: string, enabled: bool, can_reload: bool, properties: array<string, string|null>}
     */
    private function inspect(string $unit): array
    {
        $output = $this->serverOps->run(
            ['systemctl', 'show', $unit, '--property=Id,LoadState,ActiveState,UnitFileState,CanReload,MemoryCurrent,CPUUsageNSec,TasksCurrent'],
            ['feature' => 'service', 'op' => 'inspect', 'unit' => $unit],
        )->output();

        return [
            'installed' => $this->property($output, 'LoadState') === 'loaded',
            'id' => $this->property($output, 'Id'),
            'status' => $this->property($output, 'ActiveState') ?: 'inactive',
            'enabled' => $this->property($output, 'UnitFileState') === 'enabled',
            'can_reload' => $this->property($output, 'CanReload') === 'yes',
            'properties' => [
                'MemoryCurrent' => $this->property($output, 'MemoryCurrent'),
                'CPUUsageNSec' => $this->property($output, 'CPUUsageNSec'),
                'TasksCurrent' => $this->property($output, 'TasksCurrent'),
            ],
        ];
    }

    private function systemdId(string $unit): string
    {
        return str_ends_with($unit, '.service') ? $unit : "{$unit}.service";
    }

    private function property(string $output, string $key): ?string
    {
        return preg_match('/^'.$key.'=(.*)$/m', $output, $matches) ? trim($matches[1]) : null;
    }
}
