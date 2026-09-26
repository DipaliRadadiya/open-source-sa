<?php

namespace App\Http\Requests\Server\Firewall;

use App\Models\FirewallRule;
use Illuminate\Contracts\Validation\Validator;

/**
 * Refuse a rule — new or edited — that ufw would treat as one it already has.
 *
 * `CreateFirewallRule` has always rejected an identical new rule, with this
 * same `errors/firewall.duplicate` message. Updating had no equivalent: a rule
 * could be edited onto the same port/protocol/action/source as an existing
 * one, and the panel would then list the same line twice. ufw is idempotent,
 * so nothing downstream objected — deleting one of the pair changes nothing
 * visible, which reads as a broken delete.
 *
 * A trait rather than a copy of the action's check, because the update path
 * needs two things the create path does not: the fields the request did not
 * send have to come from the stored row, and the rule being edited must not
 * count as a duplicate of itself.
 *
 * Identity is port, protocol and source — **not the action**, because that is
 * how ufw counts. `ufw deny 22/tcp` beside an existing `allow 22/tcp` does not
 * add a rule, it prints "Rule updated" and *replaces* the allow, while the
 * panel went on listing both. Deleting either row then ran `ufw delete` on
 * the one rule left, so the port lost both. Found live on 2026-09-26: a deny
 * on the SSH port, then deleting it, left SSH with no rule at all and locked
 * the server out, with the panel still showing "Allow 22/tcp". An opposite
 * rule is refused with its own message: the way to turn an allow into a deny
 * is to edit it, which UpdateFirewallRule and SshLockoutGuard already handle.
 *
 * `description` is deliberately excluded: it is a label for people, and
 * letting it distinguish two rules would mean the server enforces one thing
 * and the list explains it twice.
 *
 * Used by both the create and the update request.
 */
trait RefusesDuplicateRules
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $clash = $this->clashingRule();

            if ($clash === null) {
                return;
            }

            $validator->errors()->add('port_from', $clash->action === (string) $this->resolved('action')
                ? __('errors/firewall.duplicate')
                : __('errors/firewall.conflict', ['ports' => $clash->portSpec(), 'action' => $clash->action]));
        });
    }

    private function clashingRule(): ?FirewallRule
    {
        $existing = $this->route('firewallRule');

        $query = FirewallRule::query()
            ->where('port_from', (int) $this->resolved('port_from'))
            ->where('protocol', (string) $this->resolved('protocol'));

        // Spelled out rather than relying on `where($column, null)`. Laravel
        // does turn that into `whereNull`, so this is not a fix — it is the
        // difference being visible at the call site, because `port_to` is null
        // for a single port and `source_ip` is null for "from anywhere", and
        // both are the common case rather than an edge one.
        foreach (['port_to', 'source_ip'] as $nullable) {
            $value = $this->resolved($nullable);

            $query = ($value === null || $value === '')
                ? $query->whereNull($nullable)
                : $query->where($nullable, $value);
        }

        // An update comparing against itself is not a duplicate — every save
        // that changed only the description would have been refused.
        if ($existing !== null) {
            $query->whereKeyNot($existing->getKey());
        }

        return $query->first();
    }

    /**
     * The value that will actually be saved.
     *
     * On update the request may carry only the fields being changed, so an
     * absent one has to fall back to what is stored — otherwise a PATCH that
     * sends `action` alone would be compared against a null port and match
     * nothing. {@see UpdateFirewallRuleRequest::prepareForValidation()}, which
     * does the same for the port range.
     */
    private function resolved(string $field): mixed
    {
        $existing = $this->route('firewallRule');

        if ($this->has($field)) {
            return $this->input($field);
        }

        return $existing?->{$field};
    }
}
