<?php

namespace App\Services\Server\Firewall;

use App\Services\Server\Databases\DatabaseManager;

/**
 * Ports that should not be open to the internet, and why.
 *
 * Served from here rather than kept in the frontend because the backend knows
 * which engines are actually installed and on which port, so the warning can
 * be about this server instead of a general list. It also means one place to
 * correct when a port changes, rather than two that drift.
 */
class RiskyPorts
{
    public function __construct(private DatabaseManager $databases) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $risky = [];

        foreach ($this->databases->engineNames() as $engine) {
            $port = (int) config("server.databases.engines.{$engine}.default_port");

            if ($port === 0) {
                continue;
            }

            $risky[$port] = [
                'port' => $port,
                'label' => $engine,
                'reason' => __('firewall.risky.database', ['engine' => $engine]),
                // Whether it is actually here. An engine that isn't installed
                // is still worth warning about — the port could be opened for
                // one that gets installed later — but the wording differs.
                'installed' => $this->databases->engine($engine)->available(),
            ];
        }

        foreach ((array) config('server.firewall.risky_ports', []) as $port => $label) {
            $risky[(int) $port] ??= [
                'port' => (int) $port,
                'label' => $label,
                'reason' => __('firewall.risky.service', ['service' => $label]),
                'installed' => null,
            ];
        }

        ksort($risky);

        return array_values($risky);
    }
}
