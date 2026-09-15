<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Drives an application that is still running under the old panel's PM2 daemon.
 *
 * This exists because adoption cannot restart anything. A server coming from
 * the old panel has its applications live under a per-user PM2 daemon, and the
 * customer's sites must not go down to be taken over — so the panel has to be
 * able to manage a process whose unit it does not own, for as long as the
 * customer leaves it there. That can be forever; this is a destination, not a
 * waiting room.
 *
 * **The one rule this file exists to enforce: every state change is saved.**
 * There is no unit here, so `~/.pm2/dump.pm2` is what brings the application
 * back at boot. The old panel wrote it inconsistently and each gap was a
 * separate bug — a stopped application resurrecting on reboot, an environment
 * change reverting to the credentials it replaced, a deleted application
 * taking every *other* application's boot entry with it. Saving after each
 * change, deliberately, is most of that class fixed.
 *
 * Two more rules from the same post-mortem:
 *
 * - **`pm2 cleardump` is never called.** It empties the whole user's dump. The
 *   old panel ran it when deleting one application, so deleting one site
 *   removed boot persistence for every other site that user owned — visible
 *   only at the next reboot, months later.
 * - **Status aggregates every instance.** `pm2 jlist` returns one entry per
 *   worker and the old panel stopped at the first name match, so a four-worker
 *   cluster reported a quarter of its memory.
 *
 * What this mode gives up, and why converting to a unit is worth offering:
 * no `MemoryMax` and no per-application cgroup (so one runaway site can starve
 * the others, and the numbers here are PM2's rather than the kernel's), none
 * of the unit's hardening, and logs under `~/.pm2/logs` rather than beside the
 * application where the file manager looks.
 */
class LegacyPm2Driver
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * The name the daemon knows this process by.
     *
     * Recorded at adoption rather than derived: it is whatever the old panel
     * passed to `pm2 start --name`, which was the application's name at the
     * time and has no relationship to anything this panel would compute.
     */
    public function processName(Application $application): string
    {
        return (string) ($application->pm2_process_name ?: $application->name);
    }

    /**
     * PM2 keeps its state per OS user, under `$HOME/.pm2`.
     *
     * Passed explicitly on every call rather than relied upon. The old panel's
     * single worst-behaved code path was the one that dropped `-H` from its
     * `sudo`, leaving `HOME` as root's — so commands ran as the site user
     * against root's daemon, found nothing, and reported success. Naming the
     * directory removes the question.
     */
    public function pm2Home(Application $application): string
    {
        return rtrim((string) $application->systemUser->home_path, '/').'/.pm2';
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
     * The one thing this mode does that a plain `node` unit cannot. Only
     * meaningful for a clustered process — PM2 reloads a single fork-mode
     * process by restarting it, which is a short outage rather than none.
     */
    public function reload(Application $application): ServerOpsResult
    {
        return $this->changeState('reload', $application);
    }

    /**
     * Stop the process and take it out of the daemon.
     *
     * `delete`, then `save` — never `cleardump`. The save rewrites the dump
     * without this application and leaves every other application the user
     * owns exactly where it was.
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
     * Public because conversion needs it too, and because every caller that
     * changes state has to be able to see whether it worked. A failed save is
     * not cosmetic: the application keeps running and silently loses its boot
     * entry, which nobody discovers until the machine restarts.
     */
    public function save(Application $application): ServerOpsResult
    {
        return $this->pm2(['save', '--force'], $application, 'pm2_save');
    }

    /**
     * What the daemon says right now, aggregated across every instance.
     *
     * Null when the daemon does not know this process — which is a different
     * answer from "stopped", and the old panel could not tell them apart: it
     * returned 200 with a null body whether the application was gone, the
     * daemon was down, or the wrong user had been asked.
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

        // Every entry with this name, not the first. In cluster mode `jlist`
        // returns one per worker and they are all this application.
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
            // Online if anything is: a cluster with three of four workers up
            // is degraded, not down, and the counts below say which.
            'state' => $online !== [] ? 'online' : (string) ($instances[0]['pm2_env']['status'] ?? 'unknown'),
            'instances' => count($instances),
            'online' => count($online),
            'memory' => (int) array_sum(array_map(fn (array $p) => (int) ($p['monit']['memory'] ?? 0), $instances)),
            'cpu' => (float) array_sum(array_map(fn (array $p) => (float) ($p['monit']['cpu'] ?? 0), $instances)),
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
     * The save runs even when the change failed. A partial failure is exactly
     * when the dump and reality are most likely to have diverged, and refusing
     * to record the truth because the command that produced it errored leaves
     * the worse of the two states persisted.
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
     * `runuser -u <user> -- env PM2_HOME=<dir> pm2 …`, as an argv array. The
     * old panel built `sudo -H -u <user> bash -c "…"`, which parses the whole
     * line in root's shell before `sudo` ever drops privilege — so a `$(…)` in
     * any interpolated value ran as root. Nothing here is a string a shell
     * ever sees.
     *
     * `env` rather than trusting `runuser` to set `HOME`: the variable that
     * decides which daemon is addressed should be named at the call, not
     * inherited from whatever the invocation happened to leave behind.
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
        );
    }
}
