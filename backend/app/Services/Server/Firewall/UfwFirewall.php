<?php

namespace App\Services\Server\Firewall;

use App\Contracts\Firewall;
use App\Models\FirewallRule;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * UFW (Uncomplicated Firewall) engine. Rules are always applied/removed by
 * their full spec — never by ufw's shifting rule numbers. All OS ops go
 * through ServerOps (array args, no shell injection).
 */
class UfwFirewall implements Firewall
{
    public function __construct(private ServerOps $serverOps) {}

    public function status(): array
    {
        $result = $this->serverOps->run(['ufw', 'status', 'verbose'], ['feature' => 'firewall', 'op' => 'status']);
        $output = $result->output();

        $incoming = 'deny';
        $outgoing = 'allow';
        if (preg_match('/Default:\s*(\w+)\s*\(incoming\),\s*(\w+)\s*\(outgoing\)/i', $output, $m)) {
            $incoming = strtolower($m[1]);
            $outgoing = strtolower($m[2]);
        }

        return [
            'enabled' => str_contains($output, 'Status: active'),
            'default_policy' => ['incoming' => $incoming, 'outgoing' => $outgoing],
        ];
    }

    public function apply(FirewallRule $rule): ServerOpsResult
    {
        return $this->serverOps->run(
            array_merge(['ufw'], $this->ruleArgs($rule)),
            ['feature' => 'firewall', 'op' => 'apply', 'rule' => $rule->id],
        );
    }

    public function remove(FirewallRule $rule): ServerOpsResult
    {
        return $this->serverOps->run(
            array_merge(['ufw', 'delete'], $this->ruleArgs($rule)),
            ['feature' => 'firewall', 'op' => 'remove', 'rule' => $rule->id],
        );
    }

    public function enable(): ServerOpsResult
    {
        // --force skips the interactive "proceed?" prompt.
        return $this->serverOps->run(['ufw', '--force', 'enable'], ['feature' => 'firewall', 'op' => 'enable']);
    }

    public function disable(): ServerOpsResult
    {
        return $this->serverOps->run(['ufw', 'disable'], ['feature' => 'firewall', 'op' => 'disable']);
    }

    /**
     * The ufw argument list for a rule (shared by apply + delete):
     *  - anywhere: `allow 443/tcp` · `allow 50000:50100/tcp` · `allow 8080` (all)
     *  - with source: `allow from 1.2.3.0/24 to any port 443 proto tcp`
     *
     * @return array<int, string>
     */
    private function ruleArgs(FirewallRule $rule): array
    {
        $args = [$rule->action];

        if ($rule->source_ip) {
            $args = array_merge($args, ['from', $rule->source_ip, 'to', 'any', 'port', $rule->portSpec()]);

            if ($rule->protocol !== 'all') {
                $args = array_merge($args, ['proto', $rule->protocol]);
            }

            return $args;
        }

        $args[] = $rule->protocol === 'all' ? $rule->portSpec() : $rule->portSpec().'/'.$rule->protocol;

        return $args;
    }
}
