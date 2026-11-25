<?php

namespace App\Services\Server;

use App\Contracts\PhpStack;
use App\Enums\InstallStatus;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Support\ProbeCache;

/**
 * Manages system services via systemctl. State is read live from systemd (no DB); the catalog is the
 * configured service sets plus detected php-fpm units, and only installed units surface. Protected
 * units (the panel's web server, php-fpm) cannot be stopped or disabled. All ops go through
 * ServerOps (array args, no injection).
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
        private PackageVersions $packages,
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

        // One systemctl call for the whole catalog (see inspectMany()); states come back in catalog order.
        $catalog = $this->catalog();
        $states = $this->inspectMany(array_column($catalog, 'unit'));
        // One dpkg-query for every row's version, cached until dpkg changes.
        $installed = $this->packages->installed(array_merge(...array_map(
            fn (array $service) => $this->packagesOf($service),
            $catalog,
        )));

        foreach ($catalog as $index => $service) {
            $state = $states[$index];
            $row = $this->describeState($service, $state, $installed);

            if ($row === null) {
                continue;
            }

            // Package aliases (mysql.service -> mariadb.service) probe as loaded twice; systemd's Id is
            // the canonical unit, so key rows by it.
            $unitId = $state['installed'] ? $state['id'] : null;

            if ($unitId === null || ! isset($unitIndexes[$unitId])) {
                $rows[] = $row;

                if ($unitId !== null) {
                    $unitIndexes[$unitId] = array_key_last($rows);
                }

                continue;
            }

            // Prefer the entry naming the canonical unit, so mysql -> mariadb gives one MariaDB row
            // whatever the catalog order; if none is canonical, the first alias wins.
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
     * The display shape for one catalog entry, or null when it is not installed or only an alias.
     *
     * @param  array{key: string, unit: string, label: string}  $service
     * @return array<string, mixed>|null
     */
    public function describe(array $service): ?array
    {
        $state = $this->inspect($service['unit']);

        if ($state['installed'] && $this->isAliasOfAnotherEntry($service, $state['id'])) {
            return null;
        }

        return $this->describeState($service, $state);
    }

    /**
     * The dpkg packages whose version a row shows (`packages` in the services catalog); PHP-FPM
     * rows are generated, so theirs is derived from the unit name.
     *
     * @param  array<string, mixed>  $service
     * @return array<int, string>
     */
    private function packagesOf(array $service): array
    {
        if (isset($service['packages'])) {
            return array_values((array) $service['packages']);
        }

        return preg_match('/^php\d+\.\d+-fpm$/', $service['unit']) === 1 ? [$service['unit']] : [];
    }

    /**
     * @param  array{key: string, unit: string, label: string}  $service
     * @param  array{installed: bool, id: ?string, status: string, enabled: bool, can_reload: bool, properties: array<string, string|null>}  $state
     * @return array<string, mixed>|null
     */
    /**
     * @param  array<string, string>|null  $installed  package versions already read for the whole list
     */
    private function describeState(array $service, array $state, ?array $installed = null): ?array
    {
        if (! $state['installed']) {
            // Not installed: show it if an install is pending or failed, otherwise leave it out.
            return $this->describePendingInstall($service);
        }

        $state = $this->withHealthCheck($service, $state);

        return [
            'key' => $service['key'],
            'label' => $service['label'],
            'unit' => $service['unit'],
            // Always `installed` here: one field for the frontend to switch on, separate from status.
            'state' => 'installed',
            'install_reason' => null,
            'install_message' => null,
            'retryable' => false,
            'status' => $state['status'],
            'enabled' => $state['enabled'],
            'protected' => $this->isProtected($service['unit']),
            'actions' => $this->allowedActions($service, $state['can_reload']),
            // Only services that can validate their own config get the test button.
            'testable' => $this->tester->testable($service['key']),
            // A stopped unit has no resources to report — see ServiceUsage.
            'usage' => $state['status'] === 'active' ? $this->usage->build($service['unit'], $this->usageProperties($service, $state['properties'])) : null,
            // Keys into the Logs feature; only sources that exist on the box.
            'log_keys' => $this->logKeys($service['key']),
            // `version` for display (`1.24.0`), `package_version` as dpkg has it (`1.24.0-2ubuntu7.5`);
            // null when the service has no package.
            ...$this->versionOf($service, $installed),
        ];
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, string>|null  $installed
     * @return array{version: ?string, package_version: ?string}
     */
    private function versionOf(array $service, ?array $installed): array
    {
        $packages = $this->packagesOf($service);
        $picked = $packages === [] ? null : $this->packages->pick($packages, $installed ?? $this->packages->installed($packages));

        return ['version' => $picked['version'] ?? null, 'package_version' => $picked['package_version'] ?? null];
    }

    /**
     * A row for a service whose unit is absent but whose install is in progress or failed; null when
     * nobody tried to install it.
     *
     * Reads the same `runtime_installs` rows as the setup page and `GET /databases/engines`, so the
     * screens cannot disagree. The row is inert: no actions, usage, config test or logs.
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

        // No row, or a leftover `ready` one (a finished install deletes its row): nothing to show.
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
            // The model's own sentence, so this row and the setup card word a failure the same way.
            'install_message' => $install->message(),
            'retryable' => $failed,
            /*
             * Systemd's vocabulary, so every row is manageable the same way: `status` is
             * active|inactive|failed (what the badge renders), `state` is the kind of row. An unknown
             * word would hit the badge's fallback and print the raw string. Never null: the client
             * types it as a string and a null would drop the whole response.
             */
            'status' => $failed ? 'failed' : 'inactive',
            'enabled' => false,
            'protected' => false,
            'actions' => [],
            'testable' => false,
            'usage' => null,
            'log_keys' => [],
            // Nothing installed yet; same keys as an installed row.
            'version' => null,
            'package_version' => null,
        ];
    }

    /**
     * The log sources of a service, as `logs` registry keys.
     *
     * Exposes the association only; reading still goes through the Logs feature and its permission.
     *
     * @return array<int, string>
     */
    public function logKeys(string $key): array
    {
        // php8.4-fpm → php8.4_fpm, the key LogManager derives per version.
        $candidates = match (true) {
            ($version = $this->stack->versionForService($key)) !== null => ["php{$version}_fpm"],
            // One log per cluster (`postgresql_18_main`), so no fixed `service_logs` entry can hold it.
            $key === 'postgresql' => $this->logs->postgresKeys(),
            default => (array) config("server.service_logs.{$key}", []),
        };

        return array_values(array_filter(
            $candidates,
            fn (string $candidate) => ($source = $this->logs->find($candidate)) !== null
                && $this->logs->describe($source) !== null,
        ));
    }

    /**
     * Run an action on a catalog service, including the units that really start at boot for it.
     *
     * PostgreSQL boots through its clusters (`postgresql@18-main`), each enabled on its own, and
     * `postgresql` is a meta unit: enable/disable must reach the clusters too. Start/stop/restart
     * need no help (the clusters are `PartOf=postgresql.service`).
     *
     * @param  array{unit: string, instances?: string}  $service
     */
    public function runService(array $service, string $action): ServerOpsResult
    {
        // Starting or stopping an engine changes what the Databases screen says is running.
        ProbeCache::flush();

        $result = $this->run($service['unit'], $action);

        if ($result->failed() || ! isset($service['instances']) || ! in_array($action, ['enable', 'disable'], true)) {
            return $result;
        }

        foreach ($this->instances($service['instances']) as $instance) {
            $each = $this->run($instance, $action);

            if ($each->failed()) {
                return $each;
            }
        }

        return $result;
    }

    /**
     * The resource figures a service's row should show.
     *
     * `postgresql` is a meta unit with no process of its own (MemoryCurrent is "[not set]"), so a
     * service whose catalog entry names its instances reports their sum.
     *
     * @param  array{unit: string, instances?: string}  $service
     * @param  array<string, string|null>  $properties
     * @return array<string, string|null>
     */
    private function usageProperties(array $service, array $properties): array
    {
        if (! isset($service['instances']) || ($units = $this->instances($service['instances'])) === []) {
            return $properties;
        }

        $sum = ['MemoryCurrent' => null, 'CPUUsageNSec' => null, 'TasksCurrent' => null];

        foreach ($this->inspectMany($units) as $state) {
            foreach (array_keys($sum) as $key) {
                $value = $state['properties'][$key] ?? null;

                // systemd prints "[not set]" for a figure it does not track.
                if (is_string($value) && ctype_digit($value)) {
                    $sum[$key] = (string) ((int) $sum[$key] + (int) $value);
                }
            }
        }

        return array_map(fn (?string $value) => $value, $sum + $properties);
    }

    /**
     * The units systemd knows by this pattern (`postgresql@*`), running or not.
     *
     * @return array<int, string>
     */
    private function instances(string $pattern): array
    {
        $result = $this->serverOps->run(
            ['systemctl', 'list-units', '--all', '--plain', '--no-legend', '--type=service', $pattern],
            ['feature' => 'service', 'op' => 'list_instances', 'pattern' => $pattern],
        );

        if ($result->failed()) {
            return [];
        }

        return collect(preg_split('/\r?\n/', trim($result->output())) ?: [])
            ->map(fn (string $line) => strtok(trim($line), ' '))
            ->filter(fn ($unit) => is_string($unit) && fnmatch($pattern.'.service', $unit))
            ->values()
            ->all();
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
     * Whether this unit carries the panel's own requests (its web server or PHP-FPM). Redis is
     * protected too, but restarting it does not cut the request's connection.
     */
    public function servesPanel(string $unit): bool
    {
        return $unit !== 'redis-server' && $this->isProtected($unit);
    }

    /**
     * The panel's own units; the web server comes from the recorded stack, not an nginx assumption.
     *
     * @return array<int, string>
     */
    public function protectedUnits(): array
    {
        // Redis backs the panel's queues and cache, so it is protected too.
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
        // Protected units cannot be stopped or disabled (that would take the panel offline).
        $actions = $this->isProtected($service['unit'])
            ? ['restart', 'reload', 'enable']
            : self::ACTIONS;

        // Only offer reload if the unit implements it.
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
     * PHP-FPM rows: one per installed version, plus one per version being installed or failed.
     *
     * The entries are generated, so the catalog `install` key cannot be written for them; without the
     * pending half, a version that never finished installing would not appear at all. The tracker's
     * `versions()` skips extension rows, so a PHP extension install adds no row for its version.
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

        // Installed first: array_unique keeps the first occurrence, so an installed version is
        // described by its unit.
        $versions = array_unique([...$this->stack->versions(), ...$pending]);

        $services = [];

        foreach ($versions as $version) {
            $unit = $this->stack->serviceName($version);

            // LSPHP has no per-version unit (its processes belong to the web server), so such a stack
            // contributes no rows, pending installs included.
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
     * Replace systemd's verdict where systemd cannot give one.
     *
     * Only for services with a `health` command in the catalog (PostgreSQL). Its meta unit is
     * `Type=oneshot` + `RemainAfterExit`, so ActiveState says `active` even when no cluster runs;
     * without this, the Services screen would say "Running" while the Databases screen says down.
     * The exit code is the answer (`pg_isready --quiet` returns 0 only when accepting connections).
     * Only `status` is overridden; `installed`, `enabled`, `id` and usage still come from systemd.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function withHealthCheck(array $service, array $state): array
    {
        $health = $service['health'] ?? null;

        if (! is_array($health) || $health === []) {
            return $state;
        }

        $state['status'] = $this->serverOps->run(
            $health,
            ['feature' => 'service', 'op' => 'health', 'unit' => $service['unit']],
            timeout: 15,
        )->ok ? 'active' : 'inactive';

        return $state;
    }

    /**
     * The properties one `systemctl show` asks for; one list, so batch and single calls stay in sync.
     *
     * @var string
     */
    private const SHOW_PROPERTIES = '--property=Id,LoadState,ActiveState,UnitFileState,CanReload,MemoryCurrent,CPUUsageNSec,TasksCurrent,ControlGroup';

    private function inspect(string $unit): array
    {
        return $this->inspectMany([$unit])[0];
    }

    /**
     * Inspect every unit in ONE systemctl call (one blank-line separated block per unit).
     *
     * One call replaces one per unit, which matters on a route the frontend polls every few seconds.
     *
     * Blocks are matched to units by position, not by `Id`: an alias reports its target's Id (ask for
     * `mysql` and `mariadb` and both say `Id=mariadb.service`), so keying by it would merge rows and
     * hand a service the wrong state. systemd emits a block for every requested unit, in order,
     * including missing ones (`LoadState=not-found`), so positions are safe.
     *
     * @param  array<int, string>  $units
     * @return array<int, array{installed: bool, id: ?string, status: string, enabled: bool, can_reload: bool, properties: array<string, string|null>}>
     */
    private function inspectMany(array $units): array
    {
        if ($units === []) {
            return [];
        }

        $output = $this->serverOps->run(
            ['systemctl', 'show', ...$units, self::SHOW_PROPERTIES],
            ['feature' => 'service', 'op' => 'inspect', 'unit' => implode(',', $units)],
        )->output();

        $blocks = preg_split('/\R{2,}/', trim($output)) ?: [];

        return array_map(
            // A unit with no block is read as absent, so a short reply cannot shift later units.
            fn (int $index): array => $this->parseState($blocks[$index] ?? ''),
            array_keys($units),
        );
    }

    /**
     * @return array{installed: bool, id: ?string, status: string, enabled: bool, can_reload: bool, properties: array<string, string|null>}
     */
    private function parseState(string $output): array
    {
        return [
            'installed' => $this->property($output, 'LoadState') === 'loaded',
            'id' => $this->property($output, 'Id'),
            'status' => $this->property($output, 'ActiveState') ?: 'inactive',
            'enabled' => $this->property($output, 'UnitFileState') === 'enabled',
            'can_reload' => $this->property($output, 'CanReload') === 'yes',
            // Filled here, not in ServiceUsage::build(), so a service summed from its instances
            // (postgresql@*) gets the fallback too.
            'properties' => $this->usage->withCgroupFallback([
                'MemoryCurrent' => $this->property($output, 'MemoryCurrent'),
                'CPUUsageNSec' => $this->property($output, 'CPUUsageNSec'),
                'TasksCurrent' => $this->property($output, 'TasksCurrent'),
                'ControlGroup' => $this->property($output, 'ControlGroup'),
            ]),
        ];
    }

    /**
     * Is this entry only an alias of a unit another catalog entry owns?
     *
     * MariaDB ships `mysql.service` as an alias of `mariadb.service`, so probing `mysql` on a box
     * without MySQL reports a loaded unit. {@see list()} folds that into one row; the single-entry
     * path must agree, or `PUT /services/mysql` would restart MariaDB.
     *
     * Decided on unit names alone (no extra systemctl call). Only an entry that another entry names
     * canonically is refused; if none owns the unit, nobody better can answer (as in list()).
     *
     * @param  array{key: string, unit: string, label: string}  $service
     */
    private function isAliasOfAnotherEntry(array $service, ?string $unitId): bool
    {
        // Self-canonical: the unit resolved to itself, so it is not an alias. Also stops a catalog
        // naming one unit twice from making both entries refuse each other.
        if ($unitId === null || $this->systemdId($service['unit']) === $unitId) {
            return false;
        }

        foreach ($this->catalog() as $other) {
            if ($other['key'] !== $service['key'] && $this->systemdId($other['unit']) === $unitId) {
                return true;
            }
        }

        return false;
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
