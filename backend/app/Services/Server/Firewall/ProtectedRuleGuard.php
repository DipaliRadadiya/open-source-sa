<?php

namespace App\Services\Server\Firewall;

use App\Contracts\Firewall;
use App\Models\FirewallRule;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the rules the panel seeded for itself out of reach while the firewall
 * is enforcing.
 *
 * `DeleteFirewallRule` has refused to remove a seeded rule since the feature
 * shipped; updating one was never checked. UFW has no edit, so an update is a
 * delete and an add underneath, and switching a rule off is a delete with
 * nothing added back — which meant the guarded route (delete, `422`) and the
 * unguarded one (toggle off, `200`) had exactly the same effect on the running
 * firewall. On a server with the default deny-incoming policy, turning off the
 * seeded port-443 rule takes every site on the box offline, and the panel said
 * OK to it.
 *
 * Worse, `API_REFERENCE` tells the frontend to hide the delete action on a
 * protected rule. The guarded route was the hidden one; the only control left
 * on screen was the one nothing checked.
 *
 * Deliberately *not* an origin check bolted onto the existing
 * {@see SshLockoutGuard}: that one asks whether a way in survives, and answers
 * for one port with a rule the user may well own. This asks who the rule
 * belongs to, which is a different question with a different answer — a
 * user-made rule on port 443 stays fully editable.
 */
class ProtectedRuleGuard
{
    /**
     * Fields that lock a seeded rule.
     *
     * Kept separate from `UpdateFirewallRule::SPEC` on purpose, and it is not
     * the same list: that one answers "does ufw need to be told about this",
     * this one answers "does this change what the rule lets through".
     * `enabled` is only in the second, and it is the field that started this.
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
     * `$rule` is the filled-but-unsaved model, so its dirty attributes are the
     * pending edit. Renaming a rule stays allowed: a label never reaches ufw,
     * and refusing to let someone fix a typo would be pure obstruction.
     */
    public function assertEditable(FirewallRule $rule): void
    {
        if (! array_intersect(self::LOCKED, array_keys($rule->getDirty()))) {
            return;
        }

        $this->assertUnlocked($rule, 'protected_rule_edit');
    }

    private function assertUnlocked(FirewallRule $rule, string $message): void
    {
        // The origin as stored, never as submitted. `origin` is fillable, and
        // reading it off a filled model would let a request that renamed
        // itself to `user` walk straight past the guard meant to stop it.
        if (($rule->getOriginal('origin') ?? $rule->origin) === 'user') {
            return;
        }

        // Nothing is being enforced, so nothing can be cut off. This is the
        // escape hatch, and the reason the lock is not permanent: without it
        // the seeded rules could never be adjusted or removed on a server that
        // genuinely needs port 80 shut.
        if (! $this->firewall->status()['enabled']) {
            return;
        }

        throw ValidationException::withMessages([
            'rule' => [__('errors/firewall.'.$message, ['ports' => $this->storedPortSpec($rule)])],
        ]);
    }

    /**
     * The port the rule has *now*, not the one being asked for. On an edit
     * `$rule` is already filled, so `portSpec()` would name the port the user
     * typed — and telling someone "port 8080 is managed by the panel" when
     * they are editing the panel's port-443 rule points them at the wrong row.
     */
    private function storedPortSpec(FirewallRule $rule): string
    {
        $from = $rule->getOriginal('port_from') ?? $rule->port_from;
        $to = $rule->getOriginal('port_to') ?? $rule->port_to;

        return $to ? "{$from}:{$to}" : (string) $from;
    }
}
