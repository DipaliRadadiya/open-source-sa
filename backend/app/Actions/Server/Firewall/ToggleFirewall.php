<?php

namespace App\Actions\Server\Firewall;

use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Services\ActivityLogger;

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
     */
    private function seedDefaults(): void
    {
        foreach ($this->recordDefaults->execute() as $rule) {
            $result = $this->firewall->apply($rule);

            // Never enable deny-incoming UFW unless every recovery rule was
            // accepted. Otherwise a failed SSH rule can lock out the server.
            if ($result->failed()) {
                throw new FirewallOperationException($result->reference);
            }
        }
    }
}
