<?php

namespace App\Actions\Server\Firewall;

use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Models\FirewallRule;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteFirewallRule
{
    public function __construct(
        private Firewall $firewall,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(FirewallRule $rule): void
    {
        // System-seeded rules (SSH + defaults) can't be removed while the
        // firewall is on — that's the lockout guard.
        if ($rule->isProtected() && $this->firewall->status()['enabled']) {
            throw ValidationException::withMessages([
                'rule' => [__('errors/firewall.protected_rule')],
            ]);
        }

        DB::transaction(function () use ($rule) {
            $ports = $rule->portSpec().($rule->protocol !== 'all' ? '/'.$rule->protocol : '');

            $result = $this->firewall->remove($rule);

            if ($result->failed()) {
                throw new FirewallOperationException($result->reference);
            }

            $rule->delete();

            $this->activityLogger->log('firewall.rule_removed', null, ['ports' => $ports]);
        });
    }
}
