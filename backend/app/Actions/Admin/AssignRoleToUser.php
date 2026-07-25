<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\ActivityLogger;

class AssignRoleToUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Sync the user's assigned roles (many-to-many). The FormRequest
     * enforces >= 1 role, so a user is never left role-less.
     *
     * @param  array<int, int>  $roleIds
     */
    public function execute(User $target, array $roleIds): void
    {
        $target->roles()->sync($roleIds);

        $this->activityLogger->log('user.role_assigned', $target, ['username' => $target->username]);
    }
}
