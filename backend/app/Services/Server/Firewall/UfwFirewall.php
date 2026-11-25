<?php

namespace App\Services\Server\Firewall;

use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Models\FirewallRule;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // Never fall through to `enabled: false` here: SshLockoutGuard and DeleteFirewallRule read
        // false as "nothing is blocked, deleting is fine". An unreadable firewall is a failure.
        if ($result->failed()) {
            throw new FirewallOperationException(
                $result->reference,
                busy: $result->busy,
                staleLock: $result->staleLock,
                denied: $result->denied,
            );
        }

        $output = $result->output();

        // Null when ufw does not say (an inactive ufw prints no `Default:` line); never guess.
        $incoming = null;
        $outgoing = null;
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
        return $this->exclusively(fn () => $this->applyNow($rule));
    }

    /**
     * One ufw change at a time, panel-wide.
     *
     * ufw rewrites its rules file on every change and has no reliable lock: two concurrent removals
     * left one rule in ufw, the port open while the panel said closed. A position read for `insert`
     * and the insert itself also belong together.
     */
    private function exclusively(callable $change): ServerOpsResult
    {
        $ttl = max(1, (int) config('server.firewall.lock_ttl', 300));
        $wait = max(1, (int) config('server.firewall.lock_wait', 60));

        try {
            return Cache::lock('firewall:ufw', $ttl)->block($wait, $change);
        } catch (LockTimeoutException) {
            $reference = (string) Str::uuid();
            Log::channel('server-ops')->warning('firewall lock: wait timed out', [
                'reference' => $reference,
                'waited_seconds' => $wait,
            ]);

            throw new FirewallOperationException($reference, busy: true);
        }
    }

    private function applyNow(FirewallRule $rule): ServerOpsResult
    {
        $context = ['feature' => 'firewall', 'op' => 'apply', 'rule' => $rule->id];

        // ufw stops at the first matching rule and a plain add goes to the bottom, so a deny would
        // land below "allow 22 from anywhere" and block nothing: deny/reject go on top. Appending is
        // the fallback when there is no position to insert at.
        if (in_array($rule->action, ['deny', 'reject'], true) && ($position = $this->topPosition($rule)) !== null) {
            $inserted = $this->serverOps->run(
                array_merge(['ufw', 'insert', (string) $position], $this->ruleArgs($rule)),
                $context + ['position' => $position],
            );

            if ($inserted->ok) {
                return $inserted;
            }
        }

        return $this->serverOps->run(array_merge(['ufw'], $this->ruleArgs($rule)), $context);
    }

    /**
     * Where the top of the rule list is for this rule, or null when unknown.
     *
     * ufw numbers IPv6 rules after all IPv4 ones and refuses an IPv6-only rule at position 1
     * ("Invalid position"), so an IPv6 source goes above the first IPv6 rule instead.
     */
    private function topPosition(FirewallRule $rule): ?int
    {
        $result = $this->serverOps->run(['ufw', 'status', 'numbered'], ['feature' => 'firewall', 'op' => 'status']);

        if (! $result->ok) {
            return null;
        }

        $ipv6 = $rule->source_ip !== null && str_contains($rule->source_ip, ':');

        foreach (preg_split('/\r?\n/', $result->output()) ?: [] as $line) {
            if (preg_match('/^\[\s*(\d+)\]/', $line, $match) !== 1) {
                continue;
            }

            if (! $ipv6 || str_contains($line, '(v6)')) {
                return (int) $match[1];
            }
        }

        return null;
    }

    public function remove(FirewallRule $rule): ServerOpsResult
    {
        return $this->exclusively(fn () => $this->serverOps->run(
            array_merge(['ufw', 'delete'], $this->ruleArgs($rule)),
            ['feature' => 'firewall', 'op' => 'remove', 'rule' => $rule->id],
        ));
    }

    public function enable(): ServerOpsResult
    {
        // Set the secure default (deny incoming / allow outgoing) first: a persisted default may be
        // insecure. --force skips the interactive prompt.
        $commands = [
            ['ufw', 'default', 'deny', 'incoming'],
            ['ufw', 'default', 'allow', 'outgoing'],
            ['ufw', '--force', 'enable'],
        ];

        return $this->exclusively(function () use ($commands) {
            $result = null;
            foreach ($commands as $command) {
                $result = $this->serverOps->run($command, ['feature' => 'firewall', 'op' => 'enable']);

                if ($result->failed()) {
                    return $result;
                }
            }

            return $result;
        });
    }

    public function disable(): ServerOpsResult
    {
        return $this->exclusively(fn () => $this->serverOps->run(['ufw', 'disable'], ['feature' => 'firewall', 'op' => 'disable']));
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
