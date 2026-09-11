<?php

namespace App\Actions\Server\Firewall;

use App\Models\FirewallRule;
use App\Support\SshPort;

/**
 * Records the panel's default `allow` rules — SSH plus the configured web
 * ports — without touching the firewall itself.
 *
 * Two callers, and the split between them is the point. The installer runs
 * `ufw allow` for these ports and then needs the panel to *know* it did:
 * before this existed the only thing that ever wrote a default row was
 * {@see ToggleFirewall::seedDefaults()}, which fires on an off-to-on
 * transition made through the panel. A server whose firewall was already
 * active at install time therefore showed an enabled firewall over an empty
 * rule table — the rules were on the box, the panel had no record of them,
 * and the screen read as "nothing is allowed through".
 *
 * Recording is deliberately separate from applying. The installer's stance is
 * that installing must never enable someone's firewall as a side effect, and
 * an action that applied rules here would quietly walk that back. Toggling
 * still applies, because there the user asked for it.
 */
class RecordDefaultRules
{
    /**
     * Ensure a default `allow` row exists for every port in {@see ports()}.
     *
     * Idempotent, and matched on the rule's identity rather than its origin —
     * a hand-made rule for the same port is left exactly as it is, `user`
     * origin included, so this never rewrites someone's own rule.
     *
     * @return list<FirewallRule> in {@see ports()} order
     */
    public function execute(): array
    {
        $rules = [];

        foreach ($this->ports() as $port) {
            $rules[] = FirewallRule::firstOrCreate(
                ['port_from' => $port, 'port_to' => null, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => null],
                // `enabled` is stated rather than left to the column default.
                // The default applies in the database; the model this method
                // hands back does not carry it, so a caller reading
                // `$rule->enabled` on a freshly created row gets null — which
                // is falsy, and reads as "the user switched this off". Every
                // rule recorded here is on, and now says so.
                ['origin' => 'default', 'enabled' => true],
            );
        }

        return $rules;
    }

    /**
     * The ports a default rule is recorded for.
     *
     * The port SSH is *actually* on, not the configured default. Seeding 22 on
     * a server whose SSH was moved to 2222 and then turning on deny-incoming
     * locks the operator out of their own box — the exact mistake SshPort
     * exists to prevent.
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
