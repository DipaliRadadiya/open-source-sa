<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\ActivityLogger;

class ImpersonateUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Issue a short-lived (1h) token for the target user, tagged with the
     * impersonating admin's id. The target's own permissions still gate what
     * the resulting session can do — impersonation just borrows their identity.
     *
     * @return array{user: User, token: string}
     */
    public function execute(User $admin, User $target): array
    {
        $newToken = $target->createToken('impersonation', ['*'], now()->addHour());
        $newToken->accessToken->forceFill(['impersonated_by' => $admin->getKey()])->save();

        $this->activityLogger->log('user.impersonation_started', $target, ['username' => $target->username], actor: $admin);

        return ['user' => $target, 'token' => $newToken->plainTextToken];
    }
}
