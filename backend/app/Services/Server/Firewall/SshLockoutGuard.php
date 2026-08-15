<?php

namespace App\Services\Server\Firewall;

use App\Contracts\Firewall;
use App\Models\FirewallRule;
use App\Support\SshPort;
use Illuminate\Validation\ValidationException;

/**
 * Refuses the one firewall edit that cannot be undone from the panel: removing
 * the last rule letting SSH in while the firewall is enforcing deny-incoming.
 *
 * `FirewallRule::isProtected()` already blocks deleting a *seeded* rule, but it
 * asks who created the rule rather than whether a way in survives — and
 * `ToggleFirewall::seedDefaults()` uses `firstOrCreate`, so a hand-made port-22
 * rule keeps its `user` origin and that guard never fires for it. This asks the
 * question that actually matters, which also covers range rules (`20:30`) that
 * an origin check cannot see.
 */
class SshLockoutGuard
{
    public function __construct(private Firewall $firewall) {}

    /**
     * Throw if `$rule` is the last thing allowing SSH in.
     *
     * `$replacement` is the post-edit version of the same rule for an update —
     * changing a rule that still covers the SSH port afterwards is fine.
     */
    public function assertSurvives(FirewallRule $rule, ?FirewallRule $replacement = null): void
    {
        // Nothing is being blocked, so nothing can be locked out. This is also
        // the escape hatch: disable the firewall and the rule is editable.
        if (! $this->firewall->status()['enabled']) {
            return;
        }

        $port = SshPort::current();

        if (! $this->covers($rule, $port)) {
            return;
        }

        if ($replacement && $this->covers($replacement, $port)) {
            return;
        }

        $stillOpen = FirewallRule::query()
            ->where('id', '!=', $replacement?->id ?? $rule->id)
            ->where('action', 'allow')
            ->where('enabled', true)
            ->get()
            ->contains(fn (FirewallRule $other) => $this->covers($other, $port));

        if (! $stillOpen) {
            throw ValidationException::withMessages([
                'rule' => [__('errors/firewall.ssh_lockout', ['port' => $port])],
            ]);
        }
    }

    /**
     * Whether this rule lets SSH through on `$port`.
     *
     * A source-restricted rule counts: it is still a way in, and refusing to
     * let someone tighten their own last rule would be its own kind of trap.
     */
    private function covers(FirewallRule $rule, int $port): bool
    {
        if ($rule->action !== 'allow' || ! $rule->enabled) {
            return false;
        }

        // SSH is TCP; a udp-only rule never carries it.
        if ($rule->protocol === 'udp') {
            return false;
        }

        return $port >= $rule->port_from && $port <= ($rule->port_to ?: $rule->port_from);
    }
}
