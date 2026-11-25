<?php

namespace App\Services\Server\Applications;

use App\Services\Server\ServerOps;

/**
 * Is the old panel's agent still running on this server?
 *
 * Must be answered before a server is adopted: two panels would manage the same applications,
 * and the old agent acts on its own. It runs `pm2 cleardump` when any application of a system
 * user is deleted, which empties that user's dump file and removes boot persistence for every
 * adopted `pm2`-mode application they own.
 *
 * Found by port only: the agent's HTTPS listener is hardcoded to 43210, but its unit name is a
 * build-time variable (white-labelled builds), so names cannot be matched. The port finds the
 * process and `/proc/<pid>/cgroup` names its unit. The binary path is still reported, because
 * "installed but stopped" and "never installed" need different advice.
 */
class LegacyAgentDetector
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Running right now — the answer adoption refuses on.
     */
    public function running(): bool
    {
        return $this->listening() || $this->unitActive();
    }

    /**
     * Everything found, so a refusal can say which service to stop.
     *
     * @return array{running: bool, port: bool, unit: ?string, binary: ?string}
     */
    public function describe(): array
    {
        $unit = $this->activeUnit();

        return [
            'running' => $this->listening() || $unit !== null,
            'port' => $this->listening(),
            'unit' => $unit,
            'binary' => $this->binary(),
        ];
    }

    /**
     * Anything bound to the agent's port.
     *
     * Same `ss -ltnH` parse as {@see PortAllocator}: it reads the machine, not our records.
     */
    private function listening(): bool
    {
        $result = $this->serverOps->run(
            ['ss', '-ltnH'],
            ['feature' => 'application', 'op' => 'legacy_agent_port'],
        );

        if ($result->failed()) {
            return false;
        }

        $port = (int) config('server.applications.legacy_agent_port', 43210);

        preg_match_all('/\s\S*:(\d+)\s/', $result->output(), $matches);

        return in_array($port, array_map('intval', $matches[1] ?? []), true);
    }

    private function unitActive(): bool
    {
        return $this->activeUnit() !== null;
    }

    /**
     * The unit that owns the process holding the agent's port.
     *
     * Derived, not guessed: the unit name is a build-time value any reseller can change, so no
     * list of names is complete, but systemd knows which unit a PID belongs to.
     *
     * `/proc/<pid>/cgroup` rather than `systemctl status <pid>`: a stable format
     * (`0::/system.slice/<unit>`) instead of parsing a human-facing status page.
     */
    private function activeUnit(): ?string
    {
        $pid = $this->listeningPid();

        if ($pid === null) {
            return null;
        }

        $result = $this->serverOps->run(
            ['cat', '/proc/'.$pid.'/cgroup'],
            ['feature' => 'application', 'op' => 'legacy_agent_unit', 'pid' => $pid],
        );

        if ($result->failed()) {
            return null;
        }

        if (preg_match('#/([^/\s]+\.service)\s*$#m', $result->output(), $matches) !== 1) {
            return null;
        }

        $unit = $matches[1];

        // Never PM2's own boot unit: stopping `pm2-<user>` would remove the boot hook every
        // adopted application depends on.
        return str_starts_with($unit, 'pm2-') ? null : $unit;
    }

    /** The PID listening on the agent's port, if anything is. */
    private function listeningPid(): ?int
    {
        $result = $this->serverOps->run(
            ['ss', '-ltnpH'],
            ['feature' => 'application', 'op' => 'legacy_agent_pid'],
        );

        if ($result->failed()) {
            return null;
        }

        $port = (int) config('server.applications.legacy_agent_port', 43210);

        foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
            if (preg_match('/\s\S*:'.$port.'\s/', $line) !== 1) {
                continue;
            }

            if (preg_match('/pid=(\d+)/', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function binary(): ?string
    {
        foreach ((array) config('server.applications.legacy_agent_paths', []) as $path) {
            if ($this->serverOps->probe(
                ['test', '-f', $path],
                ['feature' => 'application', 'op' => 'legacy_agent_binary'],
            )->ok) {
                return (string) $path;
            }
        }

        return null;
    }
}
