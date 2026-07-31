<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
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
        return $this->serverOps->run(
            ['test', '-f', $this->unitPath($application)],
            ['feature' => 'application', 'op' => 'unit_exists', 'application' => $application->id],
        )->ok;
    }

    private function render(Application $application, string $documentRoot): string
    {
        return View::make('server.units.node', [
            'application' => $application,
            'documentRoot' => $documentRoot,
            'user' => $application->systemUser->username,
            'exec' => $this->execStart($application),
            'path' => $this->path($application),
            'memoryMax' => (string) config('server.applications.memory_max', '512M'),
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
