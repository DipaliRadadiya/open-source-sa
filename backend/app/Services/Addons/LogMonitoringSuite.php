<?php

namespace App\Services\Addons;

use App\Exceptions\Addons\AddonException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Log Monitoring Suite (the `log-monitoring-suite` binary, built from
 * srvrsoorg/log-monitoring-suite): access-log analytics read from its own SQLite
 * database.
 *
 * Sites are known to it by its own id, which `register()` stores on the
 * application. Its `--key` is the panel's reference (`v8-<id>`), so a lost
 * answer to `applications:add` is recovered rather than added twice.
 *
 * Unlike wp-toolkit it is more than a binary: a `.env` in its working
 * directory, a SQLite database, and two systemd timer pairs that ingest and
 * prune access logs (its docs/INTEGRATION.md §2, §7a). The layout is described
 * here; AddonInstaller writes and removes it.
 */
class LogMonitoringSuite extends AddonCli
{
    public const FETCH_UNIT = 'log-monitoring-suite-fetch';

    public const PRUNE_UNIT = 'log-monitoring-suite-prune';

    /**
     * Goravel will not boot without a 32-character APP_KEY, and this addon
     * signs and encrypts nothing with it. Fixed, so rewriting the .env on an
     * update changes nothing.
     */
    private const APP_KEY = 'log-monitoring-suite-unused-key!';

    public function __construct(ServerOps $serverOps, private WebServerManager $webServers)
    {
        parent::__construct($serverOps);
    }

    public function name(): string
    {
        return 'log-monitoring-suite';
    }

    public function label(): string
    {
        return 'Log Monitoring Suite';
    }

    protected function binary(): string
    {
        return (string) config('server.addons.log_monitoring_suite.binary');
    }

    protected function workdir(): ?string
    {
        return (string) config('server.addons.log_monitoring_suite.workdir');
    }

    public function workingDirectory(): string
    {
        return (string) $this->workdir();
    }

    public function dataDirectory(): string
    {
        return (string) config('server.addons.log_monitoring_suite.data_dir');
    }

    public function envFile(): string
    {
        return $this->workingDirectory().'/.env';
    }

    public function databasePath(): string
    {
        return $this->dataDirectory().'/log-monitoring-suite.sqlite';
    }

    /** The `.env` the binary reads from its working directory. */
    public function envContents(): string
    {
        return implode("\n", [
            'APP_KEY='.self::APP_KEY,
            'DB_CONNECTION=sqlite',
            'DB_DATABASE='.$this->databasePath(),
        ])."\n";
    }

    /** @return array{0: string, 1: string} */
    public function timers(): array
    {
        return [self::FETCH_UNIT.'.timer', self::PRUNE_UNIT.'.timer'];
    }

    /** @return array{0: string, 1: string} */
    public function services(): array
    {
        return [self::FETCH_UNIT.'.service', self::PRUNE_UNIT.'.service'];
    }

    public function unitPath(string $unit): string
    {
        return rtrim((string) config('server.addons.log_monitoring_suite.units_dir'), '/').'/'.$unit;
    }

    /**
     * The four unit files, path => contents.
     *
     * Wall-clock timers rather than the OnBootSec/OnUnitActiveSec pair in the
     * addon's own docs: those count from the service's last run, and a timer
     * enabled long after boot on a service that has never run has nothing to
     * count from. RandomizedDelaySec keeps a fleet from ingesting on the same
     * second. WorkingDirectory is what finds the .env; without it the unit
     * starts in / and the binary refuses to boot.
     *
     * @return array<string, string>
     */
    public function unitFiles(int $retentionDays): array
    {
        $binary = $this->binary();
        $workdir = $this->workingDirectory();

        $service = fn (string $description, string $command): string => <<<UNIT
            # Written by the panel when Central installed Log Monitoring Suite.
            [Unit]
            Description={$description}
            Wants=network-online.target
            After=network-online.target

            [Service]
            Type=oneshot
            User=root
            WorkingDirectory={$workdir}
            ExecStart={$binary} {$command}

            UNIT;

        $timer = fn (string $description, string $schedule, string $delay): string => <<<UNIT
            # Written by the panel when Central installed Log Monitoring Suite.
            [Unit]
            Description={$description}

            [Timer]
            OnCalendar={$schedule}
            RandomizedDelaySec={$delay}
            Persistent=true

            [Install]
            WantedBy=timers.target

            UNIT;

        return [
            $this->unitPath(self::FETCH_UNIT.'.service') => $service('Log Monitoring Suite: read new access log lines', 'logs:fetch'),
            $this->unitPath(self::FETCH_UNIT.'.timer') => $timer('Log Monitoring Suite ingestion, every 15 minutes', '*:0/15', '60'),
            $this->unitPath(self::PRUNE_UNIT.'.service') => $service('Log Monitoring Suite: delete old access logs', 'logs:prune --days='.$retentionDays),
            $this->unitPath(self::PRUNE_UNIT.'.timer') => $timer('Log Monitoring Suite pruning, daily', 'daily', '10min'),
        ];
    }

    /**
     * Read back from the prune unit, which is what actually decides it — not
     * a copy kept somewhere that could disagree with it.
     */
    public function retentionDays(): ?int
    {
        $unit = @file_get_contents($this->unitPath(self::PRUNE_UNIT.'.service'));

        return is_string($unit) && preg_match('/^ExecStart=.*\s--days=(\d+)\s*$/m', $unit, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Is ingestion actually happening: both timers active, when the fetch
     * last finished, how much the database holds. Two cheap commands —
     * `systemctl show` and `du` — and never the binary itself, which would
     * also ask the licence API.
     *
     * @return array{timers_active: bool, last_fetch_at: string|null, data_bytes: int|null, retention_days: int|null}
     */
    public function health(): array
    {
        $health = ['timers_active' => false, 'last_fetch_at' => null, 'data_bytes' => null, 'retention_days' => null];

        if (! $this->installed()) {
            return $health;
        }

        [$fetchTimer, $pruneTimer] = $this->timers();
        $fetchService = $this->services()[0];

        $shown = $this->serverOps->run(
            ['systemctl', 'show', $fetchTimer, $pruneTimer, $fetchService, '--property=Id,ActiveState,ExecMainExitTimestamp'],
            ['feature' => 'addon', 'addon' => $this->name(), 'op' => 'health'],
            timeout: 15,
        );

        $units = $shown->ok ? $this->parseShow($shown->output()) : [];

        $health['timers_active'] = ($units[$fetchTimer]['ActiveState'] ?? null) === 'active'
            && ($units[$pruneTimer]['ActiveState'] ?? null) === 'active';
        $health['last_fetch_at'] = $this->timestamp($units[$fetchService]['ExecMainExitTimestamp'] ?? '');

        // Root-only directory, so `du` through ServerOps rather than PHP.
        $du = $this->serverOps->run(
            ['du', '-sb', $this->dataDirectory()],
            ['feature' => 'addon', 'addon' => $this->name(), 'op' => 'health_size'],
            timeout: 15,
        );

        if ($du->ok && preg_match('/^(\d+)\s/', $du->output(), $m) === 1) {
            $health['data_bytes'] = (int) $m[1];
        }

        $health['retention_days'] = $this->retentionDays();

        return $health;
    }

    /**
     * `systemctl show` for several units: one block per unit, blank-line
     * separated, in the order asked — keyed here by each block's own Id.
     *
     * @return array<string, array<string, string>>
     */
    private function parseShow(string $output): array
    {
        $units = [];

        foreach (preg_split('/\n\s*\n/', trim($output)) ?: [] as $block) {
            $properties = [];

            foreach (explode("\n", $block) as $line) {
                [$key, $value] = array_pad(explode('=', trim($line), 2), 2, '');
                $properties[$key] = $value;
            }

            if (($properties['Id'] ?? '') !== '') {
                $units[$properties['Id']] = $properties;
            }
        }

        return $units;
    }

    /** systemd's `Thu 2026-11-05 09:15:42 UTC`, or nothing for "never". */
    private function timestamp(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || $value === 'n/a') {
            return null;
        }

        try {
            return CarbonImmutable::parse(preg_replace('/^[A-Za-z]{3}\s+/', '', $value))->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    public function key(Application $application): string
    {
        return 'v8-'.$application->id;
    }

    /**
     * Register the site (or adopt the record already carrying its key) and
     * remember the addon's id for it.
     *
     * @return array<string, mixed> the addon's answer
     */
    public function register(Application $application): array
    {
        $timeout = (int) config('server.addons.sync_timeout');

        try {
            $answer = $this->run([
                'applications:add',
                '--name='.$application->slug,
                '--system-user='.$application->systemUser?->username,
                '--domain='.$application->domain,
                '--key='.$this->key($application),
                '--web-server='.$this->webServers->driver()->name(),
                '--ssl='.($application->certificate?->servable() ? 'true' : 'false'),
                '--monitoring=true',
            ], $timeout, ['application' => $application->id]);
        } catch (AddonException $e) {
            // Already registered under this site's own key — a retry after a
            // lost answer. Anything else holding the name or domain is a real
            // conflict and is reported as one.
            if (($e->details['code'] ?? null) === 'conflict' && ($e->details['field'] ?? null) === 'key') {
                $application->forceFill(['log_monitoring_suite_id' => (int) $e->details['application_id']])->save();

                return ['status' => 'success', 'message' => 'Application already registered', 'application' => ['id' => (int) $e->details['application_id']]];
            }

            throw $e;
        }

        $application->forceFill(['log_monitoring_suite_id' => (int) ($answer['application']['id'] ?? 0) ?: null])->save();

        return $answer;
    }

    /**
     * Unregister the site; one the addon has never heard of is already gone.
     */
    public function unregister(Application $application): void
    {
        try {
            $this->run(['applications:remove', '--key='.$this->key($application)], (int) config('server.addons.sync_timeout'), ['application' => $application->id]);
        } catch (AddonException $e) {
            if (($e->details['code'] ?? null) !== 'not_found') {
                throw $e;
            }
        }

        $application->forceFill(['log_monitoring_suite_id' => null])->save();
    }
}
