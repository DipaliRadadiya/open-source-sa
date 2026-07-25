<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;

class AdministratorRole
{
    public const SLUG = 'administrator';

    /**
     * Ensure the protected "Administrator" system role exists and holds
     * EVERY permission (view+manage). Idempotent — safe to call on every
     * deploy/seed and at first-admin registration. Returns the role.
     */
    public function ensure(): Role
    {
        $role = Role::firstOrCreate(
            ['slug' => self::SLUG],
            ['name' => 'Administrator', 'is_system' => true, 'description' => 'Full access to every permission. Managed by the system.']
        );

        $role->permissions()->sync(
            Permission::query()->pluck('id')->mapWithKeys(
                fn (int $id) => [$id => ['view' => true, 'manage' => true]]
            )->all()
        );

        return $role;
    }
}
