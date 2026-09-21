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

        // One systemctl call for the whole catalog — see inspectMany(). The
        // states come back in catalog order, so they are consumed in it.
        $catalog = $this->catalog();
        $states = $this->inspectMany(array_column($catalog, 'unit'));

        foreach ($catalog as $index => $service) {
            $state = $states[$index];
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
     * The display shape for one catalog entry, or null when it isn't installed
     * — or when it is only an alias of a unit another entry owns.
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

        $state = $this->withHealthCheck($service, $state);

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
    /**
     * Replace systemd's verdict where systemd cannot give one.
     *
     * Only for services carrying a `health` command in the catalog, which today
     * is PostgreSQL alone. Its `postgresql.service` is a meta unit —
     * `Type=oneshot`, `ExecStart=/bin/true`, `RemainAfterExit=on` — so
     * `ActiveState` reports `active` because /bin/true succeeded, and
     * `postgresql@.service` prefixes its ExecStart with `-`, so systemd ignores
     * a cluster that failed to start. Neither can say the database is down.
     *
     * Without this the Services screen would show "Running" at the same moment
     * the Databases screen, which asks `pg_isready`, says it is not — two
     * screens with opposite answers, and the reassuring one wrong.
     *
     * The exit code is the whole answer, which is why the probe runs
     * `--quiet`: `pg_isready` returns 0 only when the server is accepting
     * connections.
     *
     * Deliberately narrow. `installed`, `enabled`, `id` and the resource
     * figures still come from systemd, which answers those correctly — this
     * overrides the one field it cannot.
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
     * The properties one `systemctl show` is asked for. One list, so the batch
     * and the single-unit call cannot drift into answering different questions.
     *
     * @var string
     */
    private const SHOW_PROPERTIES = '--property=Id,LoadState,ActiveState,UnitFileState,CanReload,MemoryCurrent,CPUUsageNSec,TasksCurrent';

    private function inspect(string $unit): array
    {
        return $this->inspectMany([$unit])[0];
    }

    /**
     * Inspect every unit in ONE systemctl call.
     *
     * `systemctl show` accepts any number of units and answers with one block
     * per unit, blank-line separated. Rendering the services page asked ten
     * times for what systemd answers once: measured on a live Ubuntu 26.04 box,
     * **174 ms as ten calls against 43 ms as one**, for identical information,
     * on a route the frontend polls every three seconds.
     *
     * 🔴 **Blocks are matched to units by position, not by `Id`.** Id is the
     * canonical unit, and an alias reports its target's — ask for `mysql` and
     * `mariadb` on a MariaDB box and *both* blocks say `Id=mariadb.service`.
     * Keying by it would collapse two rows into one and hand a service the
     * wrong state, which is the same alias confusion that let
     * `PUT /services/mysql` restart MariaDB. Position is the only thing that
     * distinguishes them, and a test pins it.
     *
     * Safe to map positionally because systemd emits a block for every unit it
     * was asked about, in order, including ones that do not exist (`LoadState=
     * not-found`) — verified on the box, awkward order, alias and missing unit
     * interleaved with real ones. The exit code stays 0 in that case, so no
     * error path changes.
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
            // A unit with no block of its own is read as absent rather than as
            // another unit's state: a short reply must not shift every later
            // unit onto the wrong block.
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
            'properties' => [
                'MemoryCurrent' => $this->property($output, 'MemoryCurrent'),
                'CPUUsageNSec' => $this->property($output, 'CPUUsageNSec'),
                'TasksCurrent' => $this->property($output, 'TasksCurrent'),
            ],
        ];
    }

    /**
     * Is this entry only an alias of a unit another catalog entry owns?
     *
     * Packages expose compatibility aliases: MariaDB ships `mysql.service` as
     * an alias of `mariadb.service`, so probing `mysql` on a box with no MySQL
     * at all reports a loaded, active unit. {@see list()} has always folded
     * that away — one daemon, one row — but the single-entry path did not, and
     * the two paths disagreeing is the whole bug:
     *
     *   PUT /services/mysql {"action":"restart"}  ->  200, restarts MariaDB
     *
     * on a server whose own service list contains no `mysql` row, and whose
     * activity log then reads "Restarted the MySQL service". Confirmed live on
     * Ubuntu 26.04: MariaDB's ActiveEnterTimestamp moved, and `mysql-server`
     * was never installed. Stop would have been the same call.
     *
     * Ownership is decided on unit names alone — no extra systemctl call — so
     * this costs nothing on a path that already inspected the unit.
     *
     * Only an entry that another entry *names canonically* is refused. Where no
     * configured entry owns the resolved unit there is nobody better to answer,
     * and refusing would remove a service the panel legitimately manages; that
     * matches list(), which keeps the first alias in the same situation.
     *
     * @param  array{key: string, unit: string, label: string}  $service
     */
    private function isAliasOfAnotherEntry(array $service, ?string $unitId): bool
    {
        // Self-canonical: the unit resolved to itself, so it is not an alias.
        //
        // A short-circuit, not a rule — removing it leaves every test green,
        // because the loop below reaches the same answer for every catalog we
        // ship. It is kept for the cost (no catalog scan on the common path)
        // and because it is the only thing standing between a catalog that
        // named one unit twice and both entries refusing each other.
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
