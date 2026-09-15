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
 * Detected by port before anything else. The agent's HTTPS listener is
 * hardcoded to 43210 in its `main.go`, while its systemd unit name is a
 * build-time variable (`-X main.ServiceName=…`) that differs between builds and
 * white-label deployments — so the port is the one signal that is the same on
 * every server. The other two are corroboration, and are worth reporting
 * because "installed but stopped" and "not installed" call for different
 * advice.
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
     * The name of the agent's unit, if one is active.
     *
     * Matched by pattern rather than an exact name: the unit is named after a
     * build-time `ServiceName`, so it is `serveravatar` on the vendor's own
     * builds and something else on a white-labelled one.
     */
    private function activeUnit(): ?string
    {
        $result = $this->serverOps->run(
            ['systemctl', 'list-units', '--type=service', '--state=running', '--no-legend', '--plain', '--no-pager'],
            ['feature' => 'application', 'op' => 'legacy_agent_unit'],
        );

        if ($result->failed()) {
            return null;
        }

        foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
            $name = strtok(trim($line), " \t");

            if (is_string($name) && $name !== '' && preg_match($this->unitPattern(), $name) === 1) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The agent binary on disk, whether or not anything is running it.
     *
     * "Installed but stopped" is the state adoption wants: it means someone has
     * already taken the old panel out of the picture, and it is worth saying so
     * rather than reporting nothing found.
     */
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

    private function unitPattern(): string
    {
        return (string) config('server.applications.legacy_agent_unit_pattern', '/(serveravatar|sa-agent)/i');
    }
}
