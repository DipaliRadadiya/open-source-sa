<?php

namespace App\Services\Server\Applications;

use App\Enums\SupervisorMode;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\ManagedFile;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;

/**
 * Runs and supervises an application's own process, via systemd.
 *
 * systemd owns the process, always: one unit is one cgroup (so per-app metrics attribute correctly),
 * boot persistence is `systemctl enable`, and resource limits and hardening are native.
 *
 * PM2 is only the unit's `ExecStart`, never an alternative to it: an application asking for more than
 * one process gets `pm2-runtime` (Node's `cluster` forks the workers) while systemd keeps the boot
 * hook, the cgroup and the memory ceiling. {@see clustered()} decides, and `pm2-adoption-design.md`
 * explains why `pm2 startup`, `pm2 save` and the dump file are absent.
 *
 * An application has a process when it has a `start_command`. PHP and static sites never touch this.
 *
 * One exception, for migration: an application adopted from the old panel runs under its per-user
 * PM2 daemon and must not be restarted by a takeover. Those are marked {@see SupervisorMode::Pm2}
 * and every method here delegates to {@see LegacyPm2Driver}. The routing lives here, not in the
 * callers, so none can forget which supervisor it is talking to and operate on a unit that does not
 * exist.
 */
class ProcessSupervisor
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private NodeRuntime $node,
        private ApplicationLogDirectory $logDirectory,
        private LegacyPm2Driver $legacyPm2,
    ) {}

    /**
     * Whether this application is run by the old panel's daemon rather than a unit of ours.
     *
     * Every public method below asks this first and delegates, so no caller has to remember that two
     * supervisors exist.
     */
    public function legacy(Application $application): bool
    {
        return $application->supervisor_mode === SupervisorMode::Pm2;
    }

    public function runs(Application $application): bool
    {
        // An adopted application may have no `start_command` we could parse; what it has is a name the
        // daemon knows it by.
        if ($this->legacy($application)) {
            return filled($application->pm2_process_name) || filled($application->start_command);
        }

        return filled($application->start_command);
    }

    public function unit(Application $application): string
    {
        return 'sv-app-'.$application->id.'.service';
    }

    /**
     * The cgroup slice the unit runs in.
     *
     * Named here, not in the template, so the unit and the code that cleans it up cannot disagree.
     */
    public function slice(Application $application): string
    {
        return 'sv-app-'.$application->id.'.slice';
    }

    /**
     * How many processes this application runs.
     *
     * One unless it asked for more, never the host's core count: PM2's `-i max` would give every
     * app on an 8-core box eight workers and the per-app `MemoryMax` would stop meaning anything.
     */
    public function instances(Application $application): int
    {
        return max(1, (int) ($application->process_instances ?: 1));
    }

    /**
     * Whether this application's unit runs PM2 rather than Node directly.
     *
     * Only for clustering: a single process gains nothing from it and loses the direct signal path
     * between systemd and its process.
     */
    public function clustered(Application $application): bool
    {
        return $this->instances($application) > 1 && $this->hasForkableEntrypoint($application);
    }

    /**
     * PM2's state directory, per application.
     *
     * PM2 writes its logs, pids and socket to `$PM2_HOME` (default `~/.pm2`). The unit sets
     * `ProtectHome=read-only`, so at the default PM2 cannot start, and the error names the hardening
     * rather than the directory.
     *
     * A sibling of the log directory, not under the document root (everything there is a URL). Per
     * application, so two sites under one system user cannot see each other's processes.
     */
    public function pm2Home(Application $application): string
    {
        return $application->rootPath().'/pm2';
    }

    public function unitPath(Application $application): string
    {
        $dir = rtrim((string) config('server.applications.systemd_dir', '/etc/systemd/system'), '/');

        return $dir.'/'.$this->unit($application);
    }

    /**
     * Write the unit, load it, and start it — verifying it actually came up.
     *
     * `is-active` after `start` on purpose: `systemctl start` succeeds for a unit that starts and
     * immediately dies, so without the check a restart loop would be reported as running.
     *
     * A failure removes the unit again (as the vhost writer does): a broken file left behind is picked
     * up by the next unrelated `daemon-reload`.
     *
     * @throws ProvisioningFailedException
     */
    public function apply(Application $application, string $documentRoot, bool $start = true): void
    {
        // No unit to write: for an adopted application `apply` just means PM2 restarting it, so a
        // deploy reaches the running process.
        if ($this->legacy($application)) {
            if ($start) {
                $this->legacyPm2->restart($application);
            }

            return;
        }

        $context = ['feature' => 'application', 'op' => 'unit_write', 'application' => $application->id];

        // Before the unit: systemd creates `append:` log files but not their directory, and a unit
        // whose StandardOutput cannot be opened fails with an error that does not mention the
        // directory.
        $this->ensureLogDirectory($application);
        $this->ensurePm2($application);

        $written = $this->files->put($this->unitPath($application), $this->render($application, $documentRoot), $context);

        if ($written->failed()) {
            throw new ProvisioningFailedException('write_unit', $written->reference);
        }

        $this->daemonReload();

        $enabled = $this->systemctl('enable', $application);

        if ($enabled->failed()) {
            $this->forget($application);

            throw new ProvisioningFailedException('enable_unit', $enabled->reference);
        }

        // Enabled but not started: the unit will come up at boot, but there is nothing to run yet.
        if (! $start) {
            return;
        }

        $started = $this->systemctl('restart', $application);

        if ($started->failed() || ! $this->settled($application)) {
            $reference = $started->reference;
            $this->forget($application);

            // A reason, so the screen can say what happened, not only which step failed.
            throw new ProvisioningFailedException('start_app', $reference, 'process_exited');
        }
    }

    /**
     * Stop, disable, delete, reload.
     *
     * All four, in that order: deleting the unit while the process runs would leave a forgotten
     * application holding a port, and `systemctl` will not stop what it can no longer see.
     */
    public function remove(Application $application): void
    {
        if ($this->legacy($application)) {
            $this->legacyPm2->remove($application);

            return;
        }

        if (! $this->exists($application)) {
            return;
        }

        $this->systemctl('stop', $application);
        $this->systemctl('disable', $application);
        $this->files->delete($this->unitPath($application), [
            'feature' => 'application', 'op' => 'unit_remove', 'application' => $application->id,
        ]);
        // The policy this class used to write, on servers whose sites were never resynced. The current
        // one belongs to the site ({@see ApplicationArtifacts}).
        $this->files->delete($this->logrotatePath($application), [
            'feature' => 'application', 'op' => 'unit_remove', 'application' => $application->id,
        ]);
        $this->daemonReload();
    }

    /**
     * Rewrite the unit if the template has moved on, without touching the process: no enable, no
     * restart. systemd applies the new file on daemon-reload. Units are otherwise written only when a
     * site is created or its process changes, so a template fix would reach new sites only. True when
     * it was rewritten.
     */
    public function refreshUnit(Application $application, string $documentRoot): bool
    {
        $path = $this->unitPath($application);
        $wanted = $this->render($application, $documentRoot);
        $context = ['feature' => 'application', 'op' => 'unit_refresh', 'application' => $application->id];

        $current = $this->serverOps->run(['cat', $path], $context);

        // No unit on the box: not this method's job to create one.
        if ($current->failed() || $current->output() === $wanted) {
            return false;
        }

        if ($this->files->put($path, $wanted, $context)->failed()) {
            return false;
        }

        $this->daemonReload();

        return true;
    }

    /**
     * Write the unit for what the model says now and reload systemd — nothing else: no enable, no
     * start, and on failure no removal.
     *
     * For changing one setting of a running application (e.g. its Node version): the caller
     * restarts, checks, and on failure writes the *previous* unit back with this same method.
     * apply() would delete the unit on a failed start, turning "the new version did not work" into
     * "the site is gone".
     *
     * @throws ProvisioningFailedException when PM2 cannot be installed into the version a clustered unit names
     */
    public function rewriteUnit(Application $application, string $documentRoot): ServerOpsResult
    {
        $this->ensureLogDirectory($application);
        $this->ensurePm2($application);

        $written = $this->files->put(
            $this->unitPath($application),
            $this->render($application, $documentRoot),
            ['feature' => 'application', 'op' => 'unit_rewrite', 'application' => $application->id],
        );

        if ($written->failed()) {
            return $written;
        }

        return $this->daemonReload();
    }

    /**
     * Release the application's cgroup slice.
     *
     * systemd creates `sv-app-<id>.slice` for the units but never garbage-collects it, so a long-lived
     * box would accumulate one per deleted application.
     *
     * Not part of `remove()`: the app's unit and all its worker units share the slice, and stopping it
     * kills everything inside. This runs once, after every unit in the slice is gone.
     */
    public function releaseSlice(Application $application): ServerOpsResult
    {
        // An adopted application has no unit and so no slice of ours. Reported as done: a deprovision
        // must not stall on something that was never created.
        if ($this->legacy($application)) {
            return new ServerOpsResult(ok: true, reference: '');
        }

        return $this->serverOps->run(
            ['systemctl', 'stop', $this->slice($application)],
            ['feature' => 'application', 'op' => 'slice_release', 'application' => $application->id],
        );
    }

    public function start(Application $application): ServerOpsResult
    {
        return $this->legacy($application)
            ? $this->legacyPm2->start($application)
            : $this->systemctl('start', $application);
    }

    public function stop(Application $application): ServerOpsResult
    {
        return $this->legacy($application)
            ? $this->legacyPm2->stop($application)
            : $this->systemctl('stop', $application);
    }

    /**
     * Stop the process and keep it stopped across a reboot, for a disabled site. `stop` alone lasts
     * only until the next boot, because the unit is enabled.
     */
    public function suspend(Application $application): ServerOpsResult
    {
        // An application still under the old panel's PM2 has no unit of ours to disable.
        if ($this->legacy($application)) {
            return $this->stop($application);
        }

        $disabled = $this->systemctl('disable', $application);

        return $disabled->failed() ? $disabled : $this->stop($application);
    }

    /**
     * Undo suspend(): enable the unit again and start it.
     */
    public function resume(Application $application): ServerOpsResult
    {
        if ($this->legacy($application)) {
            return $this->start($application);
        }

        $enabled = $this->systemctl('enable', $application);

        return $enabled->failed() ? $enabled : $this->start($application);
    }

    /**
     * Restart, and keep it starting at boot: what a person pressing Restart means (Stop disables the
     * unit, so a plain restart would leave the app running only until the next reboot).
     */
    public function restartAndKeep(Application $application): ServerOpsResult
    {
        if (! $this->legacy($application)) {
            $enabled = $this->systemctl('enable', $application);

            if ($enabled->failed()) {
                return $enabled;
            }
        }

        return $this->restart($application);
    }

    public function restart(Application $application): ServerOpsResult
    {
        return $this->legacy($application)
            ? $this->legacyPm2->restart($application)
            : $this->systemctl('restart', $application);
    }

    /**
     * What systemd says right now, never what we last recorded.
     *
     * A stored status is a second answer to a question the OS already answers, and drifts the moment
     * anything restarts or crashes.
     *
     * Both supervisors answer in the same shape; fields one cannot answer (systemd has no per-worker
     * count, the daemon has no sub-state or start timestamp) are null rather than invented.
     * `cpu_percent` is percent of one core, as PM2 and top report it, so a busy cluster can pass 100.
     *
     * @return array{state: string, sub_state: ?string, since: ?string, memory: ?int, cpu_percent: ?float, pid: ?int, restarts: ?int, instances: int, online: ?int, supervisor: string}|null
     */
    public function status(Application $application): ?array
    {
        if (! $this->runs($application)) {
            return null;
        }

        if ($this->legacy($application)) {
            $status = $this->legacyPm2->status($application);

            if ($status === null) {
                return null;
            }

            return [
                'state' => $status['state'],
                // systemd's vocabulary, which the daemon has no equivalent for.
                'sub_state' => null,
                'since' => null,
                'memory' => $status['memory'],
                'cpu_percent' => $status['cpu'],
                'pid' => $status['pid'],
                'restarts' => $status['restarts'],
                'instances' => $status['instances'],
                'online' => $status['online'],
                'supervisor' => SupervisorMode::Pm2->value,
            ];
        }

        $result = $this->serverOps->run(
            [
                'systemctl', 'show', $this->unit($application),
                '--property=ActiveState,SubState,ExecMainStartTimestamp,MemoryCurrent,NRestarts,MainPID,CPUUsageNSec',
            ],
            ['feature' => 'application', 'op' => 'unit_status', 'application' => $application->id],
        );

        if ($result->failed()) {
            return null;
        }

        $output = $result->output();
        $memory = $this->property($output, 'MemoryCurrent');
        $restarts = $this->property($output, 'NRestarts');
        $pid = $this->property($output, 'MainPID');

        return [
            'state' => $this->property($output, 'ActiveState') ?? 'unknown',
            'sub_state' => $this->property($output, 'SubState'),
            'since' => $this->property($output, 'ExecMainStartTimestamp') ?: null,
            // systemd reports [not set] as a very large number when there is
            // no cgroup yet; anything non-numeric is simply unknown.
            'memory' => is_numeric($memory) ? (int) $memory : null,
            'cpu_percent' => $this->cpuPercent($application, $this->property($output, 'CPUUsageNSec')),
            // 0 is systemd's "no main process" (stopped), not a process.
            'pid' => ctype_digit((string) $pid) && (int) $pid > 0 ? (int) $pid : null,
            'restarts' => is_numeric($restarts) ? (int) $restarts : null,
            'instances' => $this->instances($application),
            // The unit is one cgroup; the live worker count is PM2's to know and nothing renders it.
            'online' => null,
            'supervisor' => SupervisorMode::Systemd->value,
        ];
    }

    public function active(Application $application): bool
    {
        if ($this->legacy($application)) {
            return $this->legacyPm2->active($application);
        }

        return $this->serverOps->run(
            ['systemctl', 'is-active', '--quiet', $this->unit($application)],
            ['feature' => 'application', 'op' => 'unit_is_active', 'application' => $application->id],
        )->ok;
    }

    /**
     * Up, and still up on the same process a moment later.
     *
     * `is-active` straight after a restart answers for a process that has not had time to fail. A
     * crash in the wait leaves the unit in its restart delay (not active) or on a new process (new
     * MainPID).
     */
    private function settled(Application $application): bool
    {
        if (! $this->active($application)) {
            return false;
        }

        $pid = $this->mainPid($application);
        $seconds = (int) config('server.applications.settle_seconds', 3);

        if ($seconds <= 0) {
            return true;
        }

        sleep($seconds);

        return $this->active($application) && $this->mainPid($application) === $pid;
    }

    private function mainPid(Application $application): string
    {
        return trim($this->serverOps->run(
            ['systemctl', 'show', '--property=MainPID', '--value', $this->unit($application)],
            ['feature' => 'application', 'op' => 'unit_main_pid', 'application' => $application->id],
        )->output());
    }

    private function exists(Application $application): bool
    {
        // `probe()` treats exit-1 as a normal "not found": WordPress and static sites have no unit.
        return $this->serverOps->probe(
            ['test', '-f', $this->unitPath($application)],
            ['feature' => 'application', 'op' => 'unit_exists', 'application' => $application->id],
        )->ok;
    }

    /**
     * Create the log directory and its logrotate policy.
     *
     * The rotation is not optional: a plain file does not vacuum itself like journald, and this one
     * is on the disk every hosted site shares, so a stack trace per request would take down every
     * site.
     *
     * The policy is {@see ApplicationLogRotation}'s, written by the directory itself. This class used
     * to write its own, running as the site user over root-written files, which failed the whole
     * nightly logrotate run.
     */
    private function ensureLogDirectory(Application $application): void
    {
        // Ownership is not this class's business: a directory the site user owns is one it can
        // unlink from, so it could delete access.log and symlink it at something of root's, and a
        // root process would append attacker-chosen request text into it. `ApplicationLogDirectory`
        // owns that decision for every writer; systemd opens `StandardOutput=append:` targets in
        // PID 1 before dropping to a user, so a root-owned directory costs this class nothing.
        $this->logDirectory->ensure($application);
    }

    /**
     * Create `$PM2_HOME`, owned by the site.
     *
     * Unlike the root-owned log directory (systemd opens `append:` targets in PID 1), PM2 creates its
     * socket and pidfile here as the site user, so the site user has to own it. Only for clustered
     * applications.
     */
    private function ensurePm2(Application $application): void
    {
        if (! $this->clustered($application)) {
            return;
        }

        // Into this application's own Node version: runtimes are per application via fnm, and a
        // unit whose ExecStart names a never-installed `pm2-runtime` fails at start with a
        // missing-path error.
        try {
            $this->node->installPm2((string) $application->node_version);
        } catch (\Throwable $e) {
            throw new ProvisioningFailedException('install_pm2', $e->getMessage());
        }

        $context = ['feature' => 'application', 'op' => 'unit_pm2_home', 'application' => $application->id];
        $home = $this->pm2Home($application);
        $user = $application->systemUser->username;

        $this->serverOps->run(['mkdir', '-p', $home], $context);
        $this->serverOps->run(['chown', $user.':'.$user, $home], $context);
        // Nothing outside the site reads PM2's socket, and the home sits in a directory other system
        // users can traverse.
        $this->serverOps->run(['chmod', '0750', $home], $context);
    }

    public function logrotatePath(Application $application): string
    {
        return '/etc/logrotate.d/sv-app-'.$application->id;
    }

    /**
     * Where this application's stdout and stderr are written.
     *
     * Beside `public_html`, never inside it (everything under the document root is a URL), and inside
     * the site rather than /var/log so it is visible in the file manager and gone with the site.
     */
    public static function logDir(Application $application): string
    {
        return $application->logsPath();
    }

    /** @return array<string, string> the log files this unit writes, by key. */
    public static function logFiles(Application $application): array
    {
        $dir = self::logDir($application);

        return ['application' => "{$dir}/app.log", 'application_error' => "{$dir}/app-error.log"];
    }

    private function render(Application $application, string $documentRoot): string
    {
        return View::make('server.units.node', [
            'application' => $application,
            'documentRoot' => $documentRoot,
            'envPath' => $application->envPath(),
            'logDir' => self::logDir($application),
            'user' => $application->systemUser->username,
            'exec' => $this->execStart($application),
            'path' => $this->path($application),
            'memoryMax' => $this->memoryMax($application),
            'slice' => $this->slice($application),
            'clustered' => $this->clustered($application),
            'pm2Home' => $this->pm2Home($application),
            'startLimitInterval' => $this->clustered($application) ? 300 : 60,
        ])->render();
    }

    /**
     * The start command, with its first word resolved to a real binary.
     *
     * `ExecStart` is not a shell: systemd execs the binary directly. The command is validated to a
     * bare `binary arg arg` form before it reaches here, so this only finds the binary (the site's
     * own Node first, then PATH at run time).
     */
    private function execStart(Application $application): string
    {
        $parts = preg_split('/\s+/', trim((string) $application->start_command)) ?: [];
        $binary = array_shift($parts) ?? '';

        if (! str_starts_with($binary, '/') && filled($application->node_version)) {
            $bin = dirname($this->node->binaryPath((string) $application->node_version));

            if ($binary === 'node' || $binary === 'npx') {
                $binary = $bin.'/'.$binary;
            }
        }

        if ($this->clustered($application)) {
            return $this->clusteredExecStart($application, $binary, $parts);
        }

        return trim($binary.' '.implode(' ', $parts));
    }

    /**
     * Whether the start command names a script PM2 can fork.
     *
     * Node's `cluster` forks a *JavaScript file*. Given a package manager or a `node_modules/.bin`
     * binary, PM2 cannot hook in and quietly runs one fork-mode process, so users who chose four
     * instances got one. The entrypoint has to be `node <file>`; `StartCommand` already refuses the
     * package managers.
     */
    public function hasForkableEntrypoint(Application $application): bool
    {
        $parts = preg_split('/\s+/', trim((string) $application->start_command)) ?: [];
        $binary = basename($parts[0] ?? '');

        return $binary === 'node' && filled($parts[1] ?? null);
    }

    /**
     * The same command, handed to `pm2-runtime` so it can be forked N times.
     *
     * `pm2-runtime`, never the `pm2` daemon: it stays in the foreground, so `Type=simple` tracks the
     * real process, `Restart=always` means something, and every worker lands in the unit's cgroup.
     *
     * `--raw` keeps the workers' output on stdout, where the unit's `StandardOutput=append:` already
     * sends it, so PM2 writes no log files of its own.
     *
     * Autorestart is left ON: PM2 restarts *workers* and systemd restarts the *parent*. With it off, a
     * worker killed by the OOM killer stays dead while the unit reports active, and the app serves on
     * N-1 workers silently.
     *
     * The `--` matters: without it PM2 reads the application's own arguments as its own. Node's
     * `cluster` hands every worker the *master's* argv, so with instances > 1 `process.argv` shows
     * PM2's command line (PM2's behaviour; the panel warns when the number is chosen).
     *
     * `$interpreter` is the resolved `node` for this application's version, passed explicitly rather
     * than left to `#!/usr/bin/env node`: runtimes are per application via fnm.
     *
     * @param  list<string>  $arguments  the start command's words after `node`
     */
    private function clusteredExecStart(Application $application, string $interpreter, array $arguments): string
    {
        // Guaranteed by `hasForkableEntrypoint()`, which gates `clustered()`: the command is
        // `node <script> [args]`.
        $script = array_shift($arguments) ?? '';

        $command = [
            $this->node->pm2RuntimePath((string) $application->node_version),
            'start', $script,
            '--interpreter', $interpreter,
            '-i', (string) $this->instances($application),
            '--name', rtrim($this->unit($application), '.service'),
            '--raw',
        ];

        if ($arguments !== []) {
            $command[] = '--';
            $command = array_merge($command, $arguments);
        }

        return implode(' ', $command);
    }

    /**
     * The unit's memory ceiling: what this application needs, else the server's default.
     *
     * `MemoryMax` is enforced by killing the process, so an app under its own minimum (n8n asks for
     * 2 GB) is killed at startup, restarts until `StartLimitBurst`, and the site answers 502 while
     * the panel reports it installed and active. The site type answers because it knows what it
     * installed; `server.applications.memory_max` stays the default for everything with no opinion.
     */
    private function memoryMax(Application $application): string
    {
        $default = (string) config('server.applications.memory_max', '512M');

        return app(SiteTypeManager::class)
            ->find((string) $application->site_type)
            ?->defaultMemoryMax() ?? $default;
    }

    /**
     * PATH for the unit: the site's Node ahead of the system's.
     *
     * Without this a pinned version is honoured at build time and ignored at run time.
     */
    private function path(Application $application): string
    {
        $base = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        if (blank($application->node_version)) {
            return $base;
        }

        return dirname($this->node->binaryPath((string) $application->node_version)).':'.$base;
    }

    private function systemctl(string $action, Application $application): ServerOpsResult
    {
        return $this->serverOps->run(
            ['systemctl', $action, $this->unit($application)],
            ['feature' => 'application', 'op' => 'unit_'.$action, 'application' => $application->id],
        );
    }

    private function daemonReload(): ServerOpsResult
    {
        return $this->serverOps->run(
            ['systemctl', 'daemon-reload'],
            ['feature' => 'application', 'op' => 'daemon_reload'],
        );
    }

    /**
     * Remove a unit that failed to come up, so a broken file is not left for
     * the next `daemon-reload` to pick up.
     */
    private function forget(Application $application): void
    {
        $this->systemctl('disable', $application);
        $this->files->delete($this->unitPath($application), [
            'feature' => 'application', 'op' => 'unit_rollback', 'application' => $application->id,
        ]);
        $this->daemonReload();
    }

    /**
     * CPU use since the last time anyone asked.
     *
     * systemd only gives total CPU time, and a rate needs two readings. Sleeping between them would
     * hold up every request, so each reading is kept and the next request (the screen polls) works out
     * the rate. The first reading says null; so does a total that went down (restart) or a gap over
     * five minutes, and the reading starts over.
     */
    private function cpuPercent(Application $application, ?string $usage): ?float
    {
        if (! ctype_digit((string) $usage)) {
            return null;
        }

        $key = "process-cpu:{$application->id}";
        $now = (int) (microtime(true) * 1e9);
        $previous = Cache::get($key);

        // Two requests a moment apart (two widgets on one screen) would divide by almost nothing: the
        // second gets the first's answer.
        if (is_array($previous) && $now - $previous['at'] < 1_000_000_000) {
            return $previous['percent'];
        }

        $percent = null;

        if (is_array($previous) && (int) $usage >= $previous['usage'] && $now - $previous['at'] <= 300_000_000_000) {
            $percent = round(((int) $usage - $previous['usage']) / ($now - $previous['at']) * 100, 1);
        }

        Cache::put($key, ['usage' => (int) $usage, 'at' => $now, 'percent' => $percent], now()->addMinutes(10));

        return $percent;
    }

    private function property(string $output, string $key): ?string
    {
        return preg_match('/^'.$key.'=(.*)$/m', $output, $matches) === 1 ? trim($matches[1]) : null;
    }
}
