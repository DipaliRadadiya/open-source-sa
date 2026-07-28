<?php

namespace App\Services\Server\Databases;

use App\Contracts\Firewall;
use App\Models\FirewallRule;

/**
 * Opens the engine port in the firewall when a DB user is given remote access
 * (reuses the Firewall feature). Open-only in P1 — auto-closing is deferred
 * because a host/port can be shared by several DB users, so removing a rule
 * on one delete could cut off others.
 */
class DatabaseFirewall
{
    public function __construct(private Firewall $firewall) {}

    /**
     * @param  string  $preference  localhost | remote | anywhere
     * @param  string  $host  IP/CIDR for `remote`; ignored otherwise
     */
    public function sync(string $engine, string $preference, string $host): void
    {
        if ($preference === 'localhost') {
            return; // nothing to open
        }

        if (! $this->firewall->status()['enabled']) {
            return; // no firewall to sync
        }

        $port = (int) config("server.databases.engines.{$engine}.default_port");
        $sourceIp = $preference === 'remote' ? $host : null; // `anywhere` = any source

        $rule = FirewallRule::query()->firstOrCreate(
            ['port_from' => $port, 'port_to' => null, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => $sourceIp],
            ['origin' => 'default', 'description' => strtoupper($engine).' remote access'],
        );

        $this->firewall->apply($rule);
    }
}
