<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\ManagedFile;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

/**
 * Runs and supervises an application's own process, via systemd.
 *
 * systemd owns the process, always: one unit is one cgroup (so per-app metrics
 * attribute correctly), boot persistence is `systemctl enable` rather than
 * PM2's `startup`/`save` dance, and resource limits and hardening are native.
 *
 * PM2 now exists here, but as the unit's `ExecStart` rather than as an
 * alternative to it. An application asking for more than one process gets
 * `pm2-runtime`, which forks workers through Node's `cluster` module while
 * systemd keeps the boot hook, the cgroup and the memory ceiling — the one
 * thing systemd cannot do alone. A single-process application never sees it.
 * {@see clustered()} for what decides, and `pm2-adoption-design.md` for why
 * `pm2 startup`, `pm2 save` and the dump file are deliberately absent.
 *
 * An application has a process when it has a `start_command`, not when it has
 * a particular serving profile. PHP and static sites never touch this.
 */
class ProcessSupervisor
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private NodeRuntime $node,
        private ApplicationLogDirectory $logDirectory,
    ) {}

    public function runs(Application $application): bool
    {
        return filled($application->start_command);
    }

    public function unit(Application $application): string
    {
        return 'sv-app-'.$application->id.'.service';
    }

    /**
     * The cgroup slice the unit runs in.
     *
     * Named here rather than in the template for the same reason the env file
     * path is: when the unit and the code that cleans it up each built the name
     * themselves, they would be free to stop agreeing.
     */
    public function slice(Application $application): string
    {
        return 'sv-app-'.$application->id.'.slice';
    }

    /**
     * How many processes this application runs.
     *
     * One unless it asked for more, and never the host's core count: PM2's
     * `-i max` reads the machine, so every application on an 8-core box would
     * claim eight workers and the per-app `MemoryMax` would stop describing
     * anything.
     */
    public function instances(Application $application): int
    {
        return max(1, (int) ($application->process_instances ?: 1));
    }

    /**
     * Whether this application's unit runs PM2 rather than Node directly.
     *
     * Clustering is the only reason to. A single-process application gains
     * nothing from the extra supervisor and loses the direct signal path
     * between systemd and its own process.
     */
    public function clustered(Application $application): bool
    {
        return $this->instances($application) > 1 && $this->hasForkableEntrypoint($application);
    }

    /**
     * PM2's state directory, per application.
     *
     * PM2 writes `logs/`, `pids/`, `pm2.pid` and its socket to `$PM2_HOME`,
     * defaulting to `~/.pm2`. The unit sets `ProtectHome=read-only`, so left
     * at the default PM2 cannot start at all — and the failure names the
     * hardening rather than the directory, which is a long way from the cause.
     *
     * A sibling of the log directory rather than a child of the document root,
     * for the reason the logs are: everything under the document root is a URL,
     * and a socket and a pidfile are not things to publish. Per application,
     * so that two sites under one system user cannot see each other's
     * processes — which is exactly what the old panel's shared per-user daemon
     * did.
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
     * `is-active` after `start` on purpose. `systemctl start` returns success
     * for a unit that starts and immediately dies, which is exactly what a bad
     * start command does. Without the check the panel would report a running
     * application that is in a restart loop.
     *
     * A failure removes the unit again, for the same reason the vhost writer
     * does: a broken file left behind is picked up by the next unrelated
     * `daemon-reload`.
     *
     * @throws ProvisioningFailedException
     */
    public function apply(Application $application, string $documentRoot, bool $start = true): void
    {
        $context = ['feature' => 'application', 'op' => 'unit_write', 'application' => $application->id];

        // Before the unit, not after: systemd creates the log *files* for
        // `append:` but not the directory holding them, and a unit whose
        // StandardOutput cannot be opened fails to start with an error that
        // says nothing about a missing directory.
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

        // Enabled but not started: the unit exists and will come up at boot,
        // but there is nothing to run yet. Starting it here would be a
        // guaranteed failure on an application whose code arrives later.
        if (! $start) {
            return;
        }

        $started = $this->systemctl('restart', $application);

        if ($started->failed() || ! $this->active($application)) {
            $reference = $started->reference;
            $this->forget($application);

            throw new ProvisioningFailedException('start_app', $reference);
        }
    }

    /**
     * Stop, disable, delete, reload.
     *
     * All four, in that order. Deleting the unit while the process still runs
     * leaves an application the panel has forgotten holding a port and serving
     * traffic — and `systemctl` will not stop what it can no longer see.
     */
    public function remove(Application $application): void
    {
        if (! $this->exists($application)) {
            return;
        }

        $this->systemctl('stop', $application);
        $this->systemctl('disable', $application);
        $this->files->delete($this->unitPath($application), [
            'feature' => 'application', 'op' => 'unit_remove', 'application' => $application->id,
        ]);
        // The logs themselves go with the site's directory; this is only the
        // rotation policy, which would otherwise be left pointing at a path
        // that no longer exists and warn on every logrotate run.
        $this->files->delete($this->logrotatePath($application), [
            'feature' => 'application', 'op' => 'unit_remove', 'application' => $application->id,
        ]);
        $this->daemonReload();
    }

    /**
     * Release the application's cgroup slice.
     *
     * The slice outlives its units: systemd creates `sv-app-<id>.slice` on
     * their behalf but does not garbage-collect it, so removing the unit
     * leaves the slice loaded and active and a long-lived box accumulates one
     * per application ever deleted.
     *
     * Deliberately not part of `remove()`. The application's own unit and all
     * of its worker units share this slice, and stopping a slice kills
     * everything still inside it — called from `remove()` it would pull the
     * workers out from under the code that is about to remove them properly.
     * This runs once, after every unit in the slice is gone.
     */
    public function releaseSlice(Application $application): ServerOpsResult
    {
        return $this->serverOps->run(
            ['systemctl', 'stop', $this->slice($application)],
            ['feature' => 'application', 'op' => 'slice_release', 'application' => $application->id],
        );
    }

    public function start(Application $application): ServerOpsResult
    {
        return $this->systemctl('start', $application);
    }

    public function stop(Application $application): ServerOpsResult
    {
        return $this->systemctl('stop', $application);
    }

    public function restart(Application $application): ServerOpsResult
    {
        return $this->systemctl('restart', $application);
    }

    /**
     * What systemd says right now — never what we last recorded.
     *
     * A stored status is a second answer to a question the OS already answers,
     * free to drift the moment anything restarts, crashes or is touched from a
     * shell.
     *
     * @return array{state: string, since: ?string, memory: ?int, restarts: ?int}|null
     */
    public function status(Application $application): ?array
    {
        if (! $this->runs($application)) {
            return null;
        }

        $result = $this->serverOps->run(
            [
                'systemctl', 'show', $this->unit($application),
                '--property=ActiveState,SubState,ExecMainStartTimestamp,MemoryCurrent,NRestarts',
            ],
            ['feature' => 'application', 'op' => 'unit_status', 'application' => $application->id],
        );

        if ($result->failed()) {
            return null;
        }

        $output = $result->output();
        $memory = $this->property($output, 'MemoryCurrent');
        $restarts = $this->property($output, 'NRestarts');

        return [
            'state' => $this->property($output, 'ActiveState') ?? 'unknown',
            'sub_state' => $this->property($output, 'SubState'),
            'since' => $this->property($output, 'ExecMainStartTimestamp') ?: null,
            // systemd reports [not set] as a very large number when there is
            // no cgroup yet; anything non-numeric is simply unknown.
            'memory' => is_numeric($memory) ? (int) $memory : null,
            'restarts' => is_numeric($restarts) ? (int) $restarts : null,
        ];
    }

    public function active(Application $application): bool
    {
        return $this->serverOps->run(
            ['systemctl', 'is-active', '--quiet', $this->unit($application)],
            ['feature' => 'application', 'op' => 'unit_is_active', 'application' => $application->id],
        )->ok;
    }

    private function exists(Application $application): bool
    {
        // `probe()` treats exit-1 as a normal "not found" result rather than a
        // failed operation — WordPress and static sites have no unit at all, and
        // that is not an error state.
        return $this->serverOps->probe(
            ['test', '-f', $this->unitPath($application)],
            ['feature' => 'application', 'op' => 'unit_exists', 'application' => $application->id],
        )->ok;
    }

    /**
     * Create the log directory, owned by the site, and give it a logrotate
     * policy.
     *
     * The rotation is not optional. journald vacuumed itself; a plain file
     * does not, and this one sits on the disk every hosted site shares — an
     * application logging a stack trace per request would fill it and take
     * down every site on the box, which is the failure the upload guard
     * exists to prevent and would be silly to reintroduce here.
     *
     * `copytruncate` specifically: systemd opens these files once and holds
     * the descriptor for the life of the process. A normal rotate renames the
     * file and leaves systemd writing to an inode nobody can read any more,
     * so the logs simply stop appearing with nothing to explain why.
     */
    private function ensureLogDirectory(Application $application): void
    {
        $context = ['feature' => 'application', 'op' => 'unit_logs', 'application' => $application->id];

        // The directory itself is no longer this class's business. It used to
        // `chown {user}:{user}` here, which was right while a process app's own
        // stdout was the only thing in it — the unit runs as that user. It now
        // also holds the web server's access and error logs, which root writes,
        // and a directory the site user owns is a directory the site user can
        // unlink from: delete access.log, symlink it at something of root's,
        // and a root process appends attacker-chosen request text into it.
        //
        // `ApplicationLogDirectory` owns that decision for every writer at
        // once. systemd opens `StandardOutput=append:` targets in PID 1, before
        // any user is dropped to, so a root-owned directory costs this class
        // nothing.
        $this->logDirectory->ensure($application);

        $this->files->put($this->logrotatePath($application), $this->renderLogrotate($application), $context);
    }

    /**
     * Create `$PM2_HOME`, owned by the site.
     *
     * Unlike the log directory — which root owns, because systemd opens
     * `append:` targets in PID 1 before dropping privileges — this one is
     * written by the application's own process. PM2 creates its socket and
     * pidfile here as the site user, so the site user has to own it.
     *
     * Only for clustered applications: a single-process unit runs `node`
     * directly and PM2 never appears.
     */
    private function ensurePm2(Application $application): void
    {
        if (! $this->clustered($application)) {
            return;
        }

        // Into this application's own Node version. Runtimes here are
        // per application via fnm, so there is no single global PM2 to install
        // once — and a unit whose ExecStart names a `pm2-runtime` that was
        // never installed fails at start with an error about a missing path.
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
        // Nothing outside the site reads PM2's socket, and the directory sits
        // in a home other system users can traverse.
        $this->serverOps->run(['chmod', '0750', $home], $context);
    }

    public function logrotatePath(Application $application): string
    {
        return '/etc/logrotate.d/sv-app-'.$application->id;
    }

    private function renderLogrotate(Application $application): string
    {
        $user = $application->systemUser->username;
        $dir = self::logDir($application);

        return <<<CONF
        # Managed by the panel. Rewritten whenever the application's unit is.
        {$dir}/*.log {
            daily
            rotate 14
            maxsize 50M
            missingok
            notifempty
            compress
            delaycompress
            # systemd holds these open for the life of the process — a rename
            # would leave it writing to an unreachable inode.
            copytruncate
            # The files live in the site's own tree and belong to it, so
            # logrotate has to act as that user rather than root.
            su {$user} {$user}
            create 0640 {$user} {$user}
        }

        CONF;
    }

    /**
     * Where this application's stdout and stderr are written.
     *
     * Beside `public_html`, never inside it: everything under the document
     * root is reachable as a URL, and an error log is the last thing to
     * publish. Inside the site rather than /var/log so it belongs to the
     * application — visible in the file manager, reachable over SFTP, and
     * gone when the site is.
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
     * `ExecStart` is not a shell — systemd execs the binary directly. The
     * command is validated to a bare `binary arg arg` form before it ever
     * reaches here, so this only has to find the binary: the site's own Node
     * first, then PATH at run time.
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
     * Cluster mode is not a flag that can be applied to any command. Node's
     * `cluster` module forks a *JavaScript file*; handed anything else — a
     * package manager, a binary in `node_modules/.bin` — PM2 cannot hook into
     * it and quietly runs a single fork-mode process instead. The old panel
     * did exactly that, and its users chose four instances and got one with no
     * error anywhere.
     *
     * So the entrypoint has to be `node <file>`. `StartCommand` already
     * refuses the package managers; this is the narrower question of whether
     * there is a file for PM2 to fork.
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
     * `pm2-runtime`, never the `pm2` daemon: it stays in the foreground, so
     * `Type=simple` tracks the real process, `Restart=always` still means
     * something, and every worker lands in the unit's cgroup. The daemon form
     * would fork away and leave systemd supervising nothing, which is how the
     * old panel ended up needing `pm2 startup` and `pm2 save` to get a boot
     * hook the unit already provides.
     *
     * `--raw` keeps the workers' output on stdout, where the unit's existing
     * `StandardOutput=append:` already sends it — so PM2 writes no log files
     * of its own and the logrotate policy beside them continues to be the only
     * one that matters.
     *
     * Autorestart is left ON, which is the opposite of what supervising a
     * single process calls for. PM2 restarts *workers* and systemd restarts
     * the *parent*; they are not two supervisors racing for one process. With
     * it off, a worker killed by the OOM killer simply stays dead — PM2 reports
     * it stopped, systemd reports the unit active, and the application serves
     * on N-1 workers with nothing anywhere saying so.
     *
     * The `--` matters: without it PM2 reads the application's own arguments as
     * its own. Note that Node's `cluster` module hands every worker the
     * *master's* argv, so an application that parses `process.argv` sees PM2's
     * command line in cluster mode and not its own. That is PM2's behaviour
     * rather than ours, it only bites when instances > 1, and the panel warns
     * about it at the point the number is chosen.
     *
     * `$interpreter` is the resolved `node` for this application's version, and
     * it is passed explicitly rather than left to PM2's `#!/usr/bin/env node`:
     * runtimes here are per application via fnm, so "whichever node is on PATH"
     * is a different answer per site and the wrong one for most of them.
     *
     * @param  list<string>  $arguments  the start command's words after `node`
     */
    private function clusteredExecStart(Application $application, string $interpreter, array $arguments): string
    {
        // Guaranteed by `hasForkableEntrypoint()`, which gates `clustered()`:
        // the command is `node <script> [args]`, so the script is the first
        // word after the interpreter and the rest belong to the application.
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
     * The unit's memory ceiling: what this application needs, else the
     * server's default.
     *
     * Server-wide 512M was applied to every Node application regardless of
     * what it was — including n8n, whose own documentation asks for 2 GB. A
     * `MemoryMax` is enforced by killing the process, so an application under
     * its own minimum does not run slowly, it is killed at startup, restarts
     * until `StartLimitBurst`, and stops. The site then answers 502 while the
     * panel reports it installed and active, which is the least debuggable
     * shape a failure can take.
     *
     * The site type answers because it is the only thing that knows what it
     * installed. An operator who wants a different figure still sets
     * `server.applications.memory_max`, which remains the default for
     * everything with no opinion.
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
     * Without this a pinned version is honoured at build time and ignored at
     * run time, so an app compiles against one Node and executes on another.
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

    private function property(string $output, string $key): ?string
    {
        return preg_match('/^'.$key.'=(.*)$/m', $output, $matches) === 1 ? trim($matches[1]) : null;
    }
}
