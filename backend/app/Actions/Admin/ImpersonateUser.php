<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Auth;

class ImpersonateUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Start impersonating the target: log them in on the cookie session and
     * remember the impersonating admin's id in the session. No token is issued
     * — impersonation runs entirely in the session. The target's own
     * permissions still gate what the session can do.
     */
    public function execute(User $admin, User $target): void
    {
        // Log in on the session (web) guard — the SPA authenticates via the
        // Sanctum stateful cookie session, which is backed by the web guard.
        Auth::guard('web')->login($target);
        session(['impersonator_id' => $admin->getKey()]);

        $this->activityLogger->log('user.impersonation_started', $target, ['username' => $target->username], actor: $admin);
    }
}
