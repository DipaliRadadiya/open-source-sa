<?php

namespace App\Actions\Server\Firewall;

use App\Models\FirewallRule;
use App\Support\SshPort;

/**
 * Records the panel's default `allow` rules — SSH plus the configured web
 * ports — without touching the firewall itself.
 *
 * The installer runs `ufw allow` for these ports and needs the panel to record it; otherwise
 * only {@see ToggleFirewall::seedDefaults()} (off-to-on in the panel) writes default rows, and an
 * already-active firewall shows an empty rule table.
 *
 * Recording is deliberately separate from applying: installing must never enable someone's
 * firewall as a side effect. Toggling still applies, because the user asked for it.
 */
class RecordDefaultRules
{
    /**
     * Ensure a default `allow` row exists for every port in {@see ports()}.
     *
     * Idempotent, and matched on the rule's identity rather than its origin: a hand-made rule
     * for the same port is left as it is, so this never rewrites someone's own rule.
     *
     * @return list<FirewallRule> in {@see ports()} order
     */
    public function execute(): array
    {
        $rules = [];

        foreach ($this->ports() as $port) {
            $rules[] = FirewallRule::firstOrCreate(
                ['port_from' => $port, 'port_to' => null, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => null],
                // `enabled` is stated here, not left to the column default: a default rule being
                // on is a fact of this action.
                ['origin' => 'default', 'enabled' => true],
            );
        }

        return $rules;
    }

    /**
     * The ports a default rule is recorded for.
     *
     * The port SSH is *actually* on, not the configured default: seeding 22 when SSH moved to
     * 2222 and then enabling deny-incoming locks the operator out (what SshPort prevents).
     *
     * @return list<int>
     */
    public function ports(): array
    {
        return array_values(array_unique(array_merge(
            [SshPort::current()],
            config('server.default_firewall_ports', []),
        )));
    }
}
