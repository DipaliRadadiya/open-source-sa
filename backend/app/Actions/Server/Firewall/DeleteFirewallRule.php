<?php

namespace App\Actions\Server\Firewall;

use App\Contracts\Firewall;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Models\FirewallRule;
use App\Services\ActivityLogger;
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

        $ports = $rule->portSpec().($rule->protocol !== 'all' ? '/'.$rule->protocol : '');

        // Not wrapped in a transaction. `ufw` changes the running firewall the
        // moment it returns, so a rollback afterwards could only undo the row —
        // leaving the port open or closed with nothing in the panel saying so.
        // The transaction never made the two systems atomic; it only held
        // SQLite's single write lock for the seconds a ufw reload takes, which
        // is long enough to fail an unrelated request with "database is
        // locked". Removing first and deleting after gives the same ordering
        // guarantee for none of that cost.
        $result = $this->firewall->remove($rule);

        if ($result->failed()) {
            throw new FirewallOperationException($result->reference);
        }

        $rule->delete();

        $this->activityLogger->log('firewall.rule_removed', null, ['ports' => $ports]);
    }
}
