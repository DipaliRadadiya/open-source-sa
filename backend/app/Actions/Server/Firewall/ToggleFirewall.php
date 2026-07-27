<?php

namespace App\Actions\Server\Firewall;

use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Models\FirewallRule;
use App\Services\ActivityLogger;

class ToggleFirewall
{
    public function __construct(
        private Firewall $firewall,
        private ActivityLogger $activityLogger,
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
     */
    private function seedDefaults(): void
    {
        $ports = array_values(array_unique(array_merge(
            [(int) config('server.ssh_port')],
            config('server.default_firewall_ports', []),
        )));

        foreach ($ports as $port) {
            $rule = FirewallRule::firstOrCreate(
                ['port_from' => $port, 'port_to' => null, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => null],
                ['origin' => 'default'],
            );

            $this->firewall->apply($rule);
        }
    }
}
