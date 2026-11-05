<?php

namespace App\Services\Addons;

use App\Enums\ApplicationStatus;
use App\Exceptions\Addons\AddonException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Installs, updates and removes the addon binaries for Central.
 *
 * An install is all or nothing. Every change it makes on the server is
 * recorded with the step that undoes it, and if any later step fails those
 * run in reverse: an update gets the previous binary and files back, a fresh
 * install leaves nothing behind. What cannot be undone is said so where it
 * happens (v7's agent is removed for good; a schema migration stays).
 *
 * Every privileged step goes through ServerOps with binaries the sudo grant
 * already has: install, cp, mv, rm, tee, chmod, systemctl, df, du.
 */
class AddonInstaller
{
    private const BACKUP_SUFFIX = '.panel-previous';

    /** @var list<array<int, string>> undo steps, run last-first on failure */
    private array $undo = [];

    /** @var list<array<int, string>> clean-up steps, run once everything worked */
    private array $commit = [];

    private string $runId = '';

    public function __construct(
        private ServerOps $serverOps,
        private AddonPackage $package,
        private WpToolkit $wpToolkit,
        private LogMonitoringSuite $logMonitoring,
    ) {}

    /** The addon a route names; the route's own pattern already refused the rest. */
    public function addon(string $name): AddonCli
    {
        return match ($name) {
            $this->wpToolkit->name() => $this->wpToolkit,
            $this->logMonitoring->name() => $this->logMonitoring,
        };
    }

    /**
     * Install the binary Central offers, or replace the installed one with it.
     *
     * @param  array{version: string, url: string, sha256: string, signature?: string|null, retention_days?: int|null}  $package
     * @return array<string, mixed> what Central reads in the run's result
     *
     * @throws AddonException
     */
    public function install(AddonCli $addon, array $package, string $runId): array
    {
        $this->runId = $runId;
        $this->undo = $this->commit = [];

        $directory = storage_path('app/addon-downloads');
        File::ensureDirectoryExists($directory, 0700);
        $download = $directory.'/'.$runId;

        $update = $addon->installed();

        try {
            $this->package->fetch($addon, $package['url'], $package['sha256'], $package['signature'] ?? null, $download);

            $lms = $addon instanceof LogMonitoringSuite;

            // Before anything changes: a server that cannot run it is refused
            // with nothing touched.
            if ($lms) {
                $this->assertDiskSpace($addon);
            }

            try {
                $v7Removed = $lms && $this->removeV7Agent($addon);

                $this->installBinary($addon, $download);

                if ($lms) {
                    $this->configure($addon, (int) ($package['retention_days'] ?? config('server.addons.log_monitoring_suite.default_retention_days')));
                }

                $sites = $lms ? $this->registerSites($addon, onlyUnregistered: $update) : null;
            } catch (Throwable $e) {
                $this->rollBack();

                throw $e;
            }

            foreach ($this->commit as $step) {
                $this->run($step, 'commit');
            }
        } finally {
            @unlink($download);
            $this->undo = $this->commit = [];
        }

        return array_filter([
            'status' => 'success',
            'action' => $update ? 'updated' : 'installed',
            'version' => $package['version'],
            'reported_version' => $addon->version(),
            'v7_removed' => $lms ? $v7Removed : null,
            'retention_days' => $lms ? $addon->retentionDays() : null,
            'sites' => $sites,
        ], fn ($value) => $value !== null);
    }

    /**
     * Remove the binary and everything that belongs to it — for Log
     * Monitoring Suite its timers, configuration and database too (all of
     * its data; decided 2026-11-05). Removing an addon that is not there
     * succeeds: the end state is the one asked for.
     *
     * @return array<string, mixed>
     *
     * @throws AddonException
     */
    public function remove(AddonCli $addon, string $runId): array
    {
        $this->runId = $runId;

        if ($addon instanceof LogMonitoringSuite) {
            $present = fn (array $units) => array_values(array_filter($units, fn (string $unit) => is_file($addon->unitPath($unit))));

            // Stopped before the database is deleted from under them. A fetch
            // already running is stopped too; that it was not running is fine.
            if (($timers = $present($addon->timers())) !== []) {
                $this->mustRemove(['systemctl', 'disable', '--now', ...$timers], 'disable_timers', $addon);
            }

            if (($services = $present($addon->services())) !== []) {
                $this->run(['systemctl', 'stop', ...$services], 'stop_services');
            }

            foreach ([...$addon->timers(), ...$addon->services()] as $unit) {
                $this->mustRemove(['rm', '-f', $addon->unitPath($unit)], 'remove_unit', $addon);
            }

            $this->mustRemove(['systemctl', 'daemon-reload'], 'daemon_reload', $addon);
        }

        $this->mustRemove(['rm', '-f', $addon->path(), $addon->path().self::BACKUP_SUFFIX], 'remove_binary', $addon);

        if ($addon instanceof LogMonitoringSuite) {
            foreach ([$addon->workingDirectory(), $addon->dataDirectory()] as $directory) {
                $this->mustRemove(['rm', '-rf', $this->safeDirectory($addon, $directory)], 'remove_directory', $addon);
            }

            Application::query()->whereNotNull('log_monitoring_suite_id')->update(['log_monitoring_suite_id' => null]);
        }

        return ['status' => 'success', 'action' => 'removed'];
    }

    /** @throws AddonException */
    private function assertDiskSpace(LogMonitoringSuite $addon): void
    {
        // The data directory may not exist yet; its filesystem is that of
        // the nearest directory that does.
        $path = $addon->dataDirectory();
        while (! is_dir($path) && $path !== dirname($path)) {
            $path = dirname($path);
        }

        $df = $this->serverOps->run(['df', '-B1', '--output=avail', $path], $this->context('disk'), timeout: 15);
        $free = preg_match('/(\d+)\s*$/', $df->ok ? $df->output() : '', $m) === 1 ? (int) $m[1] : null;

        if ($free === null) {
            throw $this->stepFailed($addon, 'disk', $df);
        }

        // The binary's own test is "more than", so this one is too.
        if ($free <= (int) config('server.addons.log_monitoring_suite.min_free_bytes')) {
            throw AddonException::of('addon_disk_low', $addon->label(), (string) round($free / 1073741824, 1));
        }
    }

    /**
     * v7's insighthub-agent ingests the same access logs; left running beside
     * this one, every line would be counted twice. Removed with its data and
     * not put back if the install then fails — v8 does not read it.
     *
     * @return bool whether there was anything to remove
     *
     * @throws AddonException
     */
    private function removeV7Agent(LogMonitoringSuite $addon): bool
    {
        $v7 = (array) config('server.addons.log_monitoring_suite.v7');
        $units = array_values(array_filter((array) $v7['unit_files'], 'is_file'));
        $directories = array_values(array_filter((array) $v7['directories'], 'is_dir'));

        if ($units === [] && $directories === []) {
            return false;
        }

        if ($units !== []) {
            $this->must(['systemctl', 'disable', '--now', (string) $v7['service']], 'v7_stop', $addon);
            $this->must(['rm', '-f', ...$units], 'v7_units', $addon);
            $this->must(['systemctl', 'daemon-reload'], 'v7_daemon_reload', $addon);
        }

        foreach ($directories as $directory) {
            $this->must(['rm', '-rf', $this->safeDirectory($addon, $directory)], 'v7_directory', $addon);
        }

        return true;
    }

    /**
     * Copy the verified download into place. The previous binary is kept
     * beside it until the whole install has worked.
     *
     * @throws AddonException
     */
    private function installBinary(AddonCli $addon, string $download): void
    {
        $path = $addon->path();

        if (is_file($path)) {
            $this->must(['cp', '-p', $path, $path.self::BACKUP_SUFFIX], 'backup_binary', $addon);
            $this->undo[] = ['mv', '-f', $path.self::BACKUP_SUFFIX, $path];
            $this->commit[] = ['rm', '-f', $path.self::BACKUP_SUFFIX];
        } else {
            $this->undo[] = ['rm', '-f', $path];
        }

        $this->must(['install', '-m', '0755', '-o', 'root', '-g', 'root', $download, $path], 'install_binary', $addon);
    }

    /**
     * Log Monitoring Suite's working directory, database, schema and timers.
     *
     * @throws AddonException
     */
    private function configure(LogMonitoringSuite $addon, int $retentionDays): void
    {
        $this->directory($addon, $addon->workingDirectory(), '0755');
        $this->directory($addon, $addon->dataDirectory(), '0700');

        $this->file($addon, $addon->envFile(), $addon->envContents(), '0600');

        // Creates the database, or brings an existing one up to this
        // version's schema. Forward only: rolled back, an update keeps the
        // migrated database. Its own errors (licence_required included) are
        // what Central is shown.
        $addon->run(['schema:migrate'], (int) config('server.addons.sync_timeout'), ['run' => $this->runId]);

        $fresh = ! is_file($addon->unitPath($addon->timers()[0]));

        // Pushed before the units are written, so on a failure it runs after
        // they are removed or restored.
        $this->undo[] = ['systemctl', 'daemon-reload'];

        foreach ($addon->unitFiles($retentionDays) as $path => $contents) {
            $this->file($addon, $path, $contents, '0644');
        }

        $this->must(['systemctl', 'daemon-reload'], 'daemon_reload', $addon);

        if ($fresh) {
            $this->undo[] = ['systemctl', 'disable', '--now', ...$addon->timers()];
        }

        $this->must(['systemctl', 'enable', '--now', ...$addon->timers()], 'enable_timers', $addon);
    }

    /**
     * Register every live site, through the same code path as the register
     * route. One site the addon refuses does not undo the install for all
     * the others; it is reported, and Central can register it again later.
     *
     * @return array{registered: int, failed: list<array{application_id: int, message: string}>}
     */
    private function registerSites(LogMonitoringSuite $addon, bool $onlyUnregistered): array
    {
        $sites = ['registered' => 0, 'failed' => []];

        $applications = Application::query()
            ->where('status', ApplicationStatus::Active)
            ->when($onlyUnregistered, fn ($query) => $query->whereNull('log_monitoring_suite_id'))
            ->get();

        foreach ($applications as $application) {
            try {
                $addon->register($application);
                $sites['registered']++;
            } catch (AddonException $e) {
                $sites['failed'][] = ['application_id' => $application->id, 'message' => $e->getMessage()];
            }
        }

        return $sites;
    }

    /** @throws AddonException */
    private function directory(AddonCli $addon, string $path, string $mode): void
    {
        $path = $this->safeDirectory($addon, $path);

        if (! is_dir($path)) {
            $this->undo[] = ['rm', '-rf', $path];
        }

        $this->must(['install', '-d', '-m', $mode, '-o', 'root', '-g', 'root', $path], 'directory', $addon);
    }

    /**
     * Write a file through tee, keeping what was there until the install has
     * worked.
     *
     * @throws AddonException
     */
    private function file(AddonCli $addon, string $path, string $contents, string $mode): void
    {
        if (is_file($path)) {
            $this->must(['cp', '-p', $path, $path.self::BACKUP_SUFFIX], 'backup_file', $addon);
            $this->undo[] = ['mv', '-f', $path.self::BACKUP_SUFFIX, $path];
            $this->commit[] = ['rm', '-f', $path.self::BACKUP_SUFFIX];
        } else {
            $this->undo[] = ['rm', '-f', $path];
        }

        $this->must(['tee', $path], 'write_file', $addon, $contents);
        $this->must(['chmod', $mode, $path], 'chmod_file', $addon);
    }

    /**
     * Undo, newest first. A step that fails is logged and the rest still run:
     * stopping halfway would leave more behind, not less.
     */
    private function rollBack(): void
    {
        foreach (array_reverse($this->undo) as $step) {
            $result = $this->run($step, 'rollback');

            if ($result->failed()) {
                Log::warning('addon install rollback step failed', ['run' => $this->runId, 'command' => $step, 'reference' => $result->reference]);
            }
        }

        $this->undo = [];
    }

    /**
     * A directory `rm -rf` may be pointed at: absolute, nothing relative in
     * it, and not one of the system's own. It comes from config, and a typo
     * there must not become `rm -rf /etc`.
     *
     * @throws AddonException
     */
    private function safeDirectory(AddonCli $addon, string $path): string
    {
        $path = rtrim($path, '/');

        $system = ['', '/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/lib64', '/opt', '/proc', '/root', '/run', '/sbin', '/srv', '/sys', '/tmp', '/usr', '/usr/local', '/usr/local/bin', '/var', '/var/lib', '/var/log'];

        if (! str_starts_with($path, '/') || str_contains($path, '/.') || in_array($path, $system, true)) {
            throw AddonException::of('addon_install_failed', $addon->label(), 'unsafe path '.$path);
        }

        return $path;
    }

    /** @throws AddonException */
    private function must(array $command, string $step, AddonCli $addon, ?string $input = null): ServerOpsResult
    {
        $result = $this->run($command, $step, $input);

        if ($result->failed()) {
            throw $this->stepFailed($addon, $step, $result);
        }

        return $result;
    }

    /** @throws AddonException */
    private function mustRemove(array $command, string $step, AddonCli $addon): void
    {
        $result = $this->run($command, $step);

        if ($result->failed()) {
            throw AddonException::of('addon_remove_failed', $addon->label(), $step.' ('.$result->reference.')');
        }
    }

    private function stepFailed(AddonCli $addon, string $step, ServerOpsResult $result): AddonException
    {
        return AddonException::of('addon_install_failed', $addon->label(), $step.' ('.$result->reference.')');
    }

    private function run(array $command, string $step, ?string $input = null): ServerOpsResult
    {
        return $this->serverOps->run($command, $this->context($step), timeout: 120, input: $input);
    }

    /** @return array<string, string> */
    private function context(string $step): array
    {
        return ['feature' => 'addon', 'op' => 'lifecycle', 'step' => $step, 'run' => $this->runId];
    }
}
