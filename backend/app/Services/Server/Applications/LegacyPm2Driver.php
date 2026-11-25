<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Drives an application still running under the old panel's per-user PM2 daemon.
 *
 * Exists because adoption cannot restart anything: the panel must manage a process whose unit
 * it does not own, for as long as the customer leaves it there.
 *
 * **Every state change is saved.** There is no unit, so `~/.pm2/dump.pm2` is what brings the
 * application back at boot. Saving after each change keeps stopped apps stopped and deleted
 * apps from taking other apps' boot entries with them.
 *
 * - **`pm2 cleardump` is never called.** It empties the whole user's dump, removing boot
 *   persistence for every other site that user owns.
 * - **Status aggregates every instance.** `pm2 jlist` returns one entry per worker.
 *
 * Gives up `MemoryMax` and per-application cgroups, the unit's hardening, and logs beside the
 * application (they live under `~/.pm2/logs`).
 */
class LegacyPm2Driver
{
    /**
     * The directory every command runs from.
     *
     * `runuser` keeps the caller's cwd, and PM2 fails to spawn its daemon (`spawn … EACCES`) when
     * the site user cannot enter it. Named here rather than inherited from the panel's directory.
     */
    private const SAFE_CWD = '/';

    public function __construct(private ServerOps $serverOps) {}

    /**
     * The name the daemon knows this process by.
     *
     * Recorded at adoption: it is whatever the old panel passed to `pm2 start --name`.
     */
    public function processName(Application $application): string
    {
        return (string) ($application->pm2_process_name ?: $application->name);
    }

    /**
     * PM2 keeps its state per OS user, under `$HOME/.pm2`.
     *
     * Passed explicitly on every call: a `sudo` without `-H` once left HOME as root's, so commands
     * ran against root's daemon, found nothing and reported success.
     */
    public function pm2Home(Application $application): string
    {
        return rtrim((string) $application->systemUser->home_path, '/').'/.pm2';
    }

    /**
     * Every process in one OS user's daemon — read-only, for discovery.
     *
     * Takes a username and home, not an `Application`: the panel does not yet know which
     * applications these belong to. Null when the daemon cannot be read, which differs from
     * "this user runs nothing" (no daemon is not a failure).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function processesFor(string $username, string $homePath): ?array
    {
        $result = $this->serverOps->run(
            [
                'runuser', '-u', $username, '--',
                'env', 'PM2_HOME='.rtrim($homePath, '/').'/.pm2',
                (string) config('server.applications.pm2_binary', 'pm2'), 'jlist',
            ],
            ['feature' => 'application', 'op' => 'pm2_jlist_user', 'user' => $username],
            timeout: (int) config('server.applications.pm2_timeout', 120),
            cwd: self::SAFE_CWD,
            // PM2 not installed for this user: `env` exits 127. That is "no
            // processes to read", not a failed server command.
            expectedExitCodes: [127],
        );

        if ($result->failed()) {
            return null;
        }

        $processes = json_decode($result->output(), true);

        return is_array($processes) ? $processes : null;
    }

    public function start(Application $application): ServerOpsResult
    {
        return $this->changeState('start', $application);
    }

    public function stop(Application $application): ServerOpsResult
    {
        return $this->changeState('stop', $application);
    }

    public function restart(Application $application): ServerOpsResult
    {
        return $this->changeState('restart', $application);
    }

    /**
     * Replace the workers one at a time, keeping the port served throughout.
     *
     * Only meaningful for a clustered process: a single fork-mode process is restarted instead,
     * with a short outage.
     */
    public function reload(Application $application): ServerOpsResult
    {
        return $this->changeState('reload', $application);
    }

    /**
     * Stop the process and take it out of the daemon.
     *
     * `delete`, then `save` — never `cleardump`, which would empty every other application's dump.
     */
    public function remove(Application $application): ServerOpsResult
    {
        $deleted = $this->pm2(['delete', $this->processName($application)], $application, 'pm2_delete');

        $this->save($application);

        return $deleted;
    }

    /**
     * Persist the current process list so it survives a reboot.
     *
     * Public because conversion needs it too. A failed save is not cosmetic: the application
     * silently loses its boot entry until the next reboot.
     */
    public function save(Application $application): ServerOpsResult
    {
        return $this->pm2(['save', '--force'], $application, 'pm2_save');
    }

    /**
     * What the daemon says right now, aggregated across every instance.
     *
     * Null when the daemon does not know this process, which differs from "stopped".
     *
     * @return array{state: string, instances: int, online: int, memory: int, cpu: float, restarts: int}|null
     */
    public function status(Application $application): ?array
    {
        $result = $this->pm2(['jlist'], $application, 'pm2_jlist');

        if ($result->failed()) {
            return null;
        }

        $processes = json_decode($result->output(), true);

        if (! is_array($processes)) {
            return null;
        }

        $name = $this->processName($application);

        // Every entry with this name, not the first: cluster mode returns one per worker.
        $instances = array_values(array_filter(
            $processes,
            fn ($process) => is_array($process) && ($process['name'] ?? null) === $name,
        ));

        if ($instances === []) {
            return null;
        }

        $online = array_filter(
            $instances,
            fn (array $process) => ($process['pm2_env']['status'] ?? null) === 'online',
        );

        return [
            // Online if anything is: a partly-up cluster is degraded, not down; the counts say which.
            'state' => $online !== [] ? 'online' : (string) ($instances[0]['pm2_env']['status'] ?? 'unknown'),
            'instances' => count($instances),
            'online' => count($online),
            'memory' => (int) array_sum(array_map(fn (array $p) => (int) ($p['monit']['memory'] ?? 0), $instances)),
            'cpu' => (float) array_sum(array_map(fn (array $p) => (float) ($p['monit']['cpu'] ?? 0), $instances)),
            // The first worker that is up; a stopped one has no process.
            'pid' => ($pid = (int) (array_values($online)[0]['pid'] ?? 0)) > 0 ? $pid : null,
            'restarts' => (int) array_sum(array_map(fn (array $p) => (int) ($p['pm2_env']['restart_time'] ?? 0), $instances)),
        ];
    }

    public function active(Application $application): bool
    {
        return ($this->status($application)['online'] ?? 0) > 0;
    }

    /**
     * A state change, followed by the save that makes it survive a reboot.
     *
     * The save runs even when the change failed: a partial failure is when the dump and reality
     * are most likely to have diverged.
     */
    private function changeState(string $action, Application $application): ServerOpsResult
    {
        $result = $this->pm2([$action, $this->processName($application)], $application, 'pm2_'.$action);

        $this->save($application);

        return $result;
    }

    /**
     * Run one `pm2` command as the site user.
     *
     * Runs `runuser -u <user> -- env PM2_HOME=<dir> pm2 …` as an argv array. Never build a shell
     * string: `sudo bash -c "…"` parses the line in root's shell, so a `$(…)` in any
     * interpolated value ran as root. `PM2_HOME` is named at the call, not inherited.
     *
     * @param  array<int, string>  $arguments
     */
    private function pm2(array $arguments, Application $application, string $op): ServerOpsResult
    {
        $user = $application->systemUser->username;

        return $this->serverOps->run(
            array_merge([
                'runuser', '-u', $user, '--',
                'env', 'PM2_HOME='.$this->pm2Home($application),
                (string) config('server.applications.pm2_binary', 'pm2'),
            ], $arguments),
            ['feature' => 'application', 'op' => $op, 'application' => $application->id],
            timeout: (int) config('server.applications.pm2_timeout', 120),
            cwd: self::SAFE_CWD,
        );
    }
}
