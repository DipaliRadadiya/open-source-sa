<?php

namespace App\Services\Server\Firewall;

use App\Contracts\Firewall;
use App\Models\FirewallRule;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the rules the panel seeded for itself out of reach while the firewall is enforcing.
 *
 * UFW has no edit, so an update is a delete plus an add, and switching a rule off is a delete.
 * Without this guard, turning off the seeded port-443 rule under the default deny-incoming
 * policy takes every site on the box offline.
 *
 * Not folded into {@see SshLockoutGuard}: that asks whether a way in survives, this asks who
 * owns the rule. A user-made rule on port 443 stays fully editable.
 */
class ProtectedRuleGuard
{
    /**
     * Fields that lock a seeded rule.
     *
     * Not the same list as `UpdateFirewallRule::SPEC` (does ufw need telling): this one is
     * "does this change what the rule lets through", so it includes `enabled`.
     */
    private const LOCKED = ['port_from', 'port_to', 'protocol', 'action', 'source_ip', 'enabled'];

    public function __construct(private Firewall $firewall) {}

    /**
     * Throw if `$rule` is panel-seeded and is being removed while the firewall
     * is enforcing.
     */
    public function assertRemovable(FirewallRule $rule): void
    {
        $this->assertUnlocked($rule, 'protected_rule');
    }

    /**
     * Throw if `$rule` is panel-seeded and something other than its
     * description is being changed while the firewall is enforcing.
     *
     * `$rule` is the filled-but-unsaved model, so its dirty attributes are the pending edit.
     * Renaming stays allowed: a label never reaches ufw.
     */
    public function assertEditable(FirewallRule $rule): void
    {
        if (! array_intersect(self::LOCKED, array_keys($rule->getDirty()))) {
            return;
        }

        // Switching a protected rule back ON only restores what the panel seeded and cuts nobody
        // off; refusing it would leave no way back once it was switched off.
        if (array_values(array_intersect(self::LOCKED, array_keys($rule->getDirty()))) === ['enabled'] && $rule->enabled) {
            return;
        }

        $this->assertUnlocked($rule, 'protected_rule_edit');
    }

    private function assertUnlocked(FirewallRule $rule, string $message): void
    {
        // The origin as stored, never as submitted: `origin` is fillable, so a request could
        // otherwise rename itself to `user` and walk past this guard.
        if (! FirewallRule::originIsProtected($rule->getOriginal('origin') ?? $rule->origin)) {
            return;
        }

        // Nothing is enforced, so nothing can be cut off. This is the escape hatch that keeps the
        // lock from being permanent.
        if (! $this->firewall->status()['enabled']) {
            return;
        }

        throw ValidationException::withMessages([
            'rule' => [__('errors/firewall.'.$message, ['ports' => $this->storedPortSpec($rule)])],
        ]);
    }

    /**
     * The port the rule has *now*, not the one being asked for: on an edit `$rule` is already
     * filled, so `portSpec()` would name the port the user typed.
     */
    private function storedPortSpec(FirewallRule $rule): string
    {
        $from = $rule->getOriginal('port_from') ?? $rule->port_from;
        $to = $rule->getOriginal('port_to') ?? $rule->port_to;

        return $to ? "{$from}:{$to}" : (string) $from;
    }
}
