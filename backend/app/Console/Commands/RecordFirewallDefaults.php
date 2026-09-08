<?php

namespace App\Console\Commands;

use App\Actions\Server\Firewall\RecordDefaultRules;
use Illuminate\Console\Command;

/**
 * Records the firewall rules the installer applied to the box.
 *
 * `install.sh` runs `ufw allow` for SSH and the web ports and deliberately
 * leaves ufw itself alone. Nothing told the panel, so on a server whose
 * firewall was already active the screen showed an enabled firewall with an
 * empty rule table: the state of the firewall is read live from ufw, the rules
 * are read from the database, and only the first half had ever been filled in.
 *
 * A console command rather than an endpoint because it runs during
 * installation, before any user exists to authenticate as — the same reasoning
 * as {@see RecordServerStack}.
 *
 * It records only; it never runs ufw. Applying rules from here would make
 * installing the panel change what the server lets through, which is exactly
 * what the installer refuses to do.
 */
class RecordFirewallDefaults extends Command
{
    protected $signature = 'firewall:record-defaults';

    protected $description = 'Record the default firewall rules the installer applied (does not change the firewall)';

    public function handle(RecordDefaultRules $action): int
    {
        $rules = $action->execute();

        $recorded = array_values(array_filter($rules, fn ($rule) => $rule->wasRecentlyCreated));

        $ports = implode(', ', array_map(fn ($rule) => (string) $rule->port_from, $rules));

        // Distinguishing the two is the point: on a re-run every rule is
        // already there, and reporting that as "recorded" would hide a command
        // that had silently stopped creating anything.
        $this->components->info(count($recorded) > 0
            ? sprintf('Recorded %d firewall rule(s); %d already present (ports: %s).', count($recorded), count($rules) - count($recorded), $ports)
            : sprintf('Firewall rules already recorded (ports: %s).', $ports));

        return self::SUCCESS;
    }
}
