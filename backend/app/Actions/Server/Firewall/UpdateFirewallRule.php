<?php

namespace App\Actions\Server\Firewall;

use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Models\FirewallRule;
use App\Services\ActivityLogger;
use App\Services\Server\ServerOpsResult;

/**
 * Edit a rule, or switch it off without losing it.
 *
 * UFW has no edit, so a changed rule is a delete and an add underneath. Two
 * things follow from that, and both matter:
 *
 *  - **A description-only change never touches UFW.** Fixing a typo in a
 *    rule's label should not involve removing a live firewall rule.
 *  - **When the rule itself changes, the new one is added before the old one
 *    is removed.** The other order leaves a gap where neither exists — and if
 *    the add then fails, a `deny` rule has quietly become "allowed".
 */
class UpdateFirewallRule
{
    /** Fields that describe the rule to UFW; anything else is just a label. */
    private const SPEC = ['port_from', 'port_to', 'protocol', 'action', 'source_ip'];

    public function __construct(
        private Firewall $firewall,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(FirewallRule $rule, array $data): FirewallRule
    {
        $original = $rule->replicate();
        $wasEnabled = (bool) $rule->enabled;

        $rule->fill($data);
        $specChanged = (bool) array_intersect(self::SPEC, array_keys($rule->getDirty()));
        $enabled = (bool) $rule->enabled;

        // Kept so the row can be put back if ufw refuses. No transaction: ufw
        // reloads take seconds and SQLite allows one writer, so holding one
        // across these calls stalls every other write in the panel — and it
        // could not have undone the ufw side anyway.
        $before = $rule->getOriginal();

        $rule->save();

        try {
            if ($specChanged && $wasEnabled && $enabled) {
                // Add first: for the moment both exist, which is harmless, and
                // at no point is the port left unguarded.
                $this->push($this->firewall->apply($rule));
                $this->push($this->firewall->remove($original));
            } elseif ($specChanged && $enabled) {
                $this->push($this->firewall->apply($rule));
            } elseif ($specChanged && $wasEnabled) {
                $this->push($this->firewall->remove($original));
            } elseif ($wasEnabled && ! $enabled) {
                $this->push($this->firewall->remove($original));
            } elseif (! $wasEnabled && $enabled) {
                $this->push($this->firewall->apply($rule));
            }

            $this->activityLogger->log(
                $wasEnabled !== $enabled
                    ? ($enabled ? 'firewall.rule_enabled' : 'firewall.rule_disabled')
                    : 'firewall.rule_updated',
                $rule,
                ['ports' => $rule->portSpec().($rule->protocol !== 'all' ? '/'.$rule->protocol : '')],
            );

            return $rule;
        } catch (FirewallOperationException $e) {
            // Put the row back so the panel keeps describing the rule ufw is
            // still enforcing. The ufw side is left as it is: a partially
            // applied change is real, and pretending otherwise by silently
            // reversing it would be a second unreviewed firewall edit.
            $rule->forceFill($before)->save();

            throw $e;
        }
    }

    private function push(ServerOpsResult $result): void
    {
        if ($result->failed()) {
            throw new FirewallOperationException($result->reference);
        }
    }
}
