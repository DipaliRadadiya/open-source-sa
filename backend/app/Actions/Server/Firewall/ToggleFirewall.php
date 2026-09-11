<?php

namespace App\Actions\Server\Firewall;

use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Services\ActivityLogger;
use App\Support\SshPort;

class ToggleFirewall
{
    public function __construct(
        private Firewall $firewall,
        private ActivityLogger $activityLogger,
        private RecordDefaultRules $recordDefaults,
    ) {}

    /**
     * Enable or disable the firewall. Enabling first seeds the default allow
     * rules (SSH + the panel/web ports) so the box is never locked out, then
     * turns UFW on. Disabling leaves the DB rules intact (re-enable restores).
     *
     * @return array{enabled: bool, default_policy: array{incoming: string, outgoing: string}}
     */
    public function execute(bool $enabled): array
    {
        if ($enabled) {
            $this->seedDefaults();
            $result = $this->firewall->enable();
        } else {
            $result = $this->firewall->disable();
        }

        if ($result->failed()) {
            throw new FirewallOperationException($result->reference);
        }

        $this->activityLogger->log($enabled ? 'firewall.enabled' : 'firewall.disabled');

        return $this->firewall->status();
    }

    /**
     * Ensure a default `allow` rule exists (and is applied) for SSH plus the
     * configured panel/web ports. Idempotent — an existing rule for a port is
     * left as-is (a user rule keeps its `user` origin).
     *
     * Which ports, and the rows themselves, belong to
     * {@see RecordDefaultRules}: the installer records the same set without
     * applying anything, and two copies of that list would eventually
     * disagree about which port SSH is on.
     *
     * **A rule the panel records as off is not applied.** This used to apply
     * every default rule regardless of its `enabled` flag, which is how the
     * table and the box came to disagree: {@see ProtectedRuleGuard} lets a
     * seeded rule be switched off while the firewall is not enforcing, and
     * turning the firewall back on then re-opened the port while the rule
     * stayed off on screen — and, because the guard locks it again the moment
     * the firewall is enforcing, left it stuck in a state that was also a lie.
     * The panel showing a closed port that is open is the 2026-09-08 bug over
     * again, one direction reversed.
     *
     * SSH is the exception, and has to be: the port is the way back in, and a
     * box whose only door is recorded shut is one `ufw enable` from being
     * unreachable. That rule is switched back on and applied, and the change
     * is written to the activity trail rather than made silently.
     */
    private function seedDefaults(): void
    {
        $sshPort = SshPort::current();

        foreach ($this->recordDefaults->execute() as $rule) {
            // Single-port `allow` on the port SSH actually listens on — the
            // same resolution `RecordDefaultRules` seeds by, so the two cannot
            // disagree about which rule is the recovery rule.
            $isRecovery = (int) $rule->port_from === $sshPort && $rule->port_to === null;

            if (! $rule->enabled) {
                if (! $isRecovery) {
                    continue;
                }

                $rule->update(['enabled' => true]);
                $this->activityLogger->log('firewall.rule_enabled', null, ['ports' => (string) $sshPort]);
            }

            $result = $this->firewall->apply($rule);

            // Never enable deny-incoming UFW unless every recovery rule was
            // accepted. Otherwise a failed SSH rule can lock out the server.
            if ($result->failed()) {
                throw new FirewallOperationException($result->reference);
            }
        }
    }
}
