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

        // role_user pivot rows cascadeOnDelete — assigned users simply lose
        // this role. (System roles are blocked from deletion upstream.)
        $role->delete();

        $this->activityLogger->log('role.deleted', null, ['name' => $name]);
    }
}
