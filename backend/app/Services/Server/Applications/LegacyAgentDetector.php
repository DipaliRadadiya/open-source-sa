<?php

namespace App\Services\Server\Applications;

use App\Services\Server\ServerOps;

/**
 * Is the old panel's agent still running on this server?
 *
 * This has to be answered before a server is adopted, because the two panels
 * would otherwise both be managing the same applications. That is not a race
 * in the millisecond sense — PM2 serialises commands through its daemon — it is
 * two control planes with different opinions about the same state, and the old
 * agent acts on its own without being asked.
 *
 * The specific loss, which is not hypothetical: the old agent runs
 * `pm2 cleardump` when *any* application belonging to a system user is deleted,
 * and `cleardump` empties that user's entire dump file. Adopted applications in
 * `pm2` mode depend on that dump to come back at boot. So a customer deleting
 * one unrelated site in the old panel, weeks after migrating, silently removes
 * boot persistence for every adopted application that user owns — discovered at
 * the next reboot, when the sites do not come back.
 *
 * Found by port, and only by port. The agent's HTTPS listener is hardcoded to
 * 43210 in its `main.go`; its unit name is a build-time variable
 * (`-X main.ServiceName=…`). An early version matched unit names against a
 * pattern and a real white-labelled server disproved it immediately — there the
 * agent is `sureshcloud.service` running `/sureshcloud/sureshcloud-agent`, which
 * no list of names would have caught.
 *
 * So the port finds the process, and the process names its own unit via
 * `/proc/<pid>/cgroup`. The binary path is still reported, because "installed
 * but stopped" and "never installed" call for different advice.
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
     * Everything found, for a message that tells someone what to actually do.
     *
     * A refusal that says only "the old agent is running" leaves the operator
     * hunting for a service whose name they may not know, on a box they may
     * have inherited.
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
     * Same `ss -ltnH` parse {@see PortAllocator} uses, for the same reason:
     * it answers about the machine rather than about our records.
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
     * Derived, not guessed. The first version matched unit names against a
     * pattern, and a real white-labelled v7 server proved that wrong within a
     * minute: its agent runs as `sureshcloud.service`, from
     * `/sureshcloud/sureshcloud-agent`. The name comes from a build-time
     * `-X main.ServiceName=…` and can be anything a reseller chose, so no list
     * of names can be complete — but the listener is hardcoded to 43210 in the
     * agent's own source, and systemd will say which unit a PID belongs to.
     *
     * `/proc/<pid>/cgroup` rather than `systemctl status <pid>`: one file read
     * with a stable format (`0::/system.slice/<unit>`) instead of parsing a
     * human-facing status page. Verified against both the agent and an
     * unrelated unit on a live box.
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

        // A last guard rather than a way of finding it: whatever owns that
        // port, it must never be PM2's own boot unit. Stopping `pm2-<user>`
        // would take out the boot hook every adopted application depends on.
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
