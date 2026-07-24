<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\ActivityLogger;

class AssignRoleToUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(User $target, ?int $roleId): void
    {
        $target->update(['role_id' => $roleId]);

        $this->activityLogger->log('user.role_assigned', $target, [
            'username' => $target->username,
            'role_id' => $roleId,
        ]);
    }
}
