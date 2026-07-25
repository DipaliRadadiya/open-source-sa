<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ActivityLogger;
use Laravel\Sanctum\PersonalAccessToken;

class StopImpersonating
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Revoke the impersonation token, ending the impersonated session. The
     * admin's own token is untouched — the frontend just switches back to it.
     * Logged with the admin (the impersonator) as the actor.
     */
    public function execute(User $target, PersonalAccessToken $token): void
    {
        $admin = User::find($token->impersonated_by);

        $token->delete();

        $this->activityLogger->log('user.impersonation_stopped', $target, ['username' => $target->username], actor: $admin);
    }
}
