<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Auth;

class StopImpersonating
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * End the impersonated session: re-log in the original admin and clear the
     * impersonator marker from the session. Logged with the admin (the
     * impersonator) as the actor.
     */
    public function execute(User $target, User $admin): void
    {
        // Re-log in the admin on the session (web) guard.
        Auth::guard('web')->login($admin);
        session()->forget('impersonator_id');

        $this->activityLogger->log('user.impersonation_stopped', $target, ['username' => $target->username], actor: $admin);
    }
}
