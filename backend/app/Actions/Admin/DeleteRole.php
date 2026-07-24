<?php

namespace App\Actions\Admin;

use App\Models\Role;
use App\Services\ActivityLogger;

class DeleteRole
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(Role $role): void
    {
        $name = $role->name;

        // users.role_id is nullOnDelete() at the DB level — assigned users
        // simply lose the role assignment, not deleted themselves.
        $role->delete();

        $this->activityLogger->log('role.deleted', null, ['name' => $name]);
    }
}
