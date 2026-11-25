<?php

namespace App\Http\Requests\Server\Firewall;

use App\Models\FirewallRule;
use Illuminate\Contracts\Validation\Validator;

/**
 * Refuse a rule, new or edited, that ufw would treat as one it already has.
 *
 * Used by both the create and the update request. A trait because the update path must fill
 * unsent fields from the stored row and must not count the edited rule as its own duplicate.
 *
 * Identity is port, protocol and source, **not the action**, because that is how ufw counts:
 * `ufw deny 22/tcp` beside `allow 22/tcp` *replaces* the allow ("Rule updated"), so listing both
 * and deleting one removes the port's only rule and can lock out SSH. An opposite rule is refused
 * with its own message; to turn an allow into a deny, edit it (UpdateFirewallRule and
 * SshLockoutGuard handle that). `description` is excluded: it is only a label.
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

        // Explicit `whereNull`: `port_to` is null for a single port and `source_ip` for "from
        // anywhere", both common cases worth seeing at the call site.
        foreach (['port_to', 'source_ip'] as $nullable) {
            $value = $this->resolved($nullable);

            $query = ($value === null || $value === '')
                ? $query->whereNull($nullable)
                : $query->where($nullable, $value);
        }

        // An update must not match itself, or a description-only save would be refused.
        if ($existing !== null) {
            $query->whereKeyNot($existing->getKey());
        }

        return $query->first();
    }

    /**
     * The value that will actually be saved.
     *
     * On update the request may carry only changed fields, so an absent one falls back to the
     * stored value. See {@see UpdateFirewallRuleRequest::prepareForValidation()} (port range).
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
