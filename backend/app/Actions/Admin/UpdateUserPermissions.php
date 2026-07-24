<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PermissionResolver;

class UpdateUserPermissions
{
    public function __construct(
        private PermissionResolver $permissionResolver,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<int, array{level: string, name: string, view: bool, manage: bool}>  $permissions
     */
    public function execute(User $target, array $permissions): void
    {
        $target->permissions()->sync($this->permissionResolver->resolve($permissions));

        $this->activityLogger->log('user.permissions_updated', $target, ['username' => $target->username]);
    }
}
