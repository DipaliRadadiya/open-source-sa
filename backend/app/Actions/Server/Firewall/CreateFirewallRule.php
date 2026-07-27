<?php

namespace App\Actions\Server\Firewall;

use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Models\FirewallRule;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateFirewallRule
{
    public function __construct(
        private Firewall $firewall,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{port_from: int, port_to?: int|null, protocol: string, action: string, source_ip?: string|null, description?: string|null}  $data
     */
    public function execute(array $data): FirewallRule
    {
        // Reject an identical rule (same port/proto/action/source).
        $duplicate = FirewallRule::query()
            ->where('port_from', $data['port_from'])
            ->where('port_to', $data['port_to'] ?? null)
            ->where('protocol', $data['protocol'])
            ->where('action', $data['action'])
            ->where('source_ip', $data['source_ip'] ?? null)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'port_from' => [__('errors/firewall.duplicate')],
            ]);
        }

        return DB::transaction(function () use ($data) {
            $rule = FirewallRule::create([
                'port_from' => $data['port_from'],
                'port_to' => $data['port_to'] ?? null,
                'protocol' => $data['protocol'],
                'action' => $data['action'],
                'source_ip' => $data['source_ip'] ?? null,
                'description' => $data['description'] ?? null,
                'origin' => 'user',
            ]);

            $result = $this->firewall->apply($rule);

            if ($result->failed()) {
                throw new FirewallOperationException($result->reference);
            }

            $this->activityLogger->log('firewall.rule_added', $rule, ['ports' => $this->ports($rule)]);

            return $rule;
        });
    }

    private function ports(FirewallRule $rule): string
    {
        return $rule->portSpec().($rule->protocol !== 'all' ? '/'.$rule->protocol : '');
    }
}
