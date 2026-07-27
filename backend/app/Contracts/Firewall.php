<?php

namespace App\Contracts;

use App\Models\FirewallRule;
use App\Services\Server\ServerOpsResult;

/**
 * A server firewall engine. UFW is the only implementation today; firewalld
 * (RHEL) can be added later without touching the callers.
 */
interface Firewall
{
    /**
     * Live state read from the engine (detect-don't-trust — no stored flag).
     *
     * @return array{enabled: bool, default_policy: array{incoming: string, outgoing: string}}
     */
    public function status(): array;

    public function apply(FirewallRule $rule): ServerOpsResult;

    public function remove(FirewallRule $rule): ServerOpsResult;

    public function enable(): ServerOpsResult;

    public function disable(): ServerOpsResult;
}
