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
     * @return array{enabled: bool, default_policy: array{incoming: ?string, outgoing: ?string}}
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
     * Ensure a default `allow` rule exists (and is applied) for SSH plus the panel/web ports.
     * Idempotent: an existing rule is left as-is. The port list lives in {@see RecordDefaultRules},
     * so the installer and this action cannot disagree about which port SSH is on.
     *
     * A rule the panel records as off is not applied, or the table and the box would disagree
     * (see {@see ProtectedRuleGuard}). SSH is the exception: it is switched back on, applied and
     * logged, because a box whose only door is recorded shut is one `ufw enable` from unreachable.
     */
    private function seedDefaults(): void
    {
        $sshPort = SshPort::current();

        foreach ($this->recordDefaults->execute() as $rule) {
            // Single-port `allow` on the port SSH listens on: the recovery rule.
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
