<?php

namespace App\Actions\Server\Firewall;

use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Models\FirewallRule;
use App\Services\ActivityLogger;
use App\Services\Server\Firewall\ProtectedRuleGuard;
use App\Services\Server\Firewall\SshLockoutGuard;
use App\Services\Server\ServerOpsResult;
use App\Support\ActivityChanges;

/**
 * Edit a rule, or switch it off without losing it.
 *
 * UFW has no edit, so a changed rule is a delete and an add underneath:
 *
 *  - **A description-only change never touches UFW.**
 *  - **When the rule itself changes, the new one is added before the old one is removed.**
 *    The other order leaves a gap where neither exists, and if the add then fails a `deny`
 *    rule has quietly become "allowed".
 */
class UpdateFirewallRule
{
    /** Fields that describe the rule to UFW; anything else is just a label. */
    private const SPEC = ['port_from', 'port_to', 'protocol', 'action', 'source_ip'];

    /** What a rule's activity entry compares before and after. */
    private const LOGGED = ['description', 'port_from', 'port_to', 'protocol', 'action', 'source_ip', 'enabled'];

    public function __construct(
        private Firewall $firewall,
        private ActivityLogger $activityLogger,
        private SshLockoutGuard $sshLockoutGuard,
        private ProtectedRuleGuard $protectedRuleGuard,
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

        // A seeded rule is the panel's own; editing one would remove it without meeting the
        // removal guard (an edit ends in `ufw delete`). Both guards run before the row is
        // saved, so a refusal changes nothing.
        $this->protectedRuleGuard->assertEditable($rule);

        // Switching off, denying, or moving the last SSH rule off the port locks you out
        // just like deleting it.
        $this->sshLockoutGuard->assertSurvives($original, $rule);

        // Kept so the row can be put back if ufw refuses. No transaction: ufw reloads take
        // seconds, and a held SQLite write lock would stall every other write.
        $before = $rule->getOriginal();

        $rule->save();

        try {
            if ($specChanged && $wasEnabled && $enabled) {
                // Add first: both existing briefly is harmless; the port is never unguarded.
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
                [
                    'ports' => $rule->summary(),
                    'changes' => ActivityChanges::between($before, $rule->only(self::LOGGED), self::LOGGED),
                ],
            );

            return $rule;
        } catch (FirewallOperationException $e) {
            // Put the row back so it matches what ufw still enforces. The ufw side is left
            // alone: silently reversing a partial change would be a second unreviewed edit.
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
