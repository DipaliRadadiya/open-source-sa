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
 * systemd rather than PM2 as the default, for reasons recorded in the deploy
 * design: one unit is one cgroup (so per-app metrics attribute correctly), boot
 * persistence is `systemctl enable` rather than PM2's save/startup dance, and
 * resource limits and hardening are native. PM2 arrives later as a *process
 * mode* running under a unit — `pm2-runtime` keeps cluster mode while systemd
 * keeps ownership of boot, cgroup and limits.
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
    ) {}

    public function runs(Application $application): bool
    {
        return filled($application->start_command);
    }

    public function unit(Application $application): string
    {
        return 'sv-app-'.$application->id.'.service';
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
        $dir = self::logDir($application);
        $user = $application->systemUser->username;
        $context = ['feature' => 'application', 'op' => 'unit_logs', 'application' => $application->id];

        $this->serverOps->run(['mkdir', '-p', $dir], $context);
        $this->serverOps->run(['chown', "{$user}:{$user}", $dir], $context);
        // Readable by the owner only: an application's own log is as sensitive
        // as whatever it decided to print, which is not a decision the panel
        // gets to audit.
        $this->serverOps->run(['chmod', '0750', $dir], $context);

        $this->files->put($this->logrotatePath($application), $this->renderLogrotate($application), $context);
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
        return $application->rootPath().'/logs';
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
            'logDir' => self::logDir($application),
            'user' => $application->systemUser->username,
            'exec' => $this->execStart($application),
            'path' => $this->path($application),
            'memoryMax' => $this->memoryMax($application),
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

        return trim($binary.' '.implode(' ', $parts));
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
