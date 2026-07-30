<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;

class AdministratorRole
{
    public const SLUG = 'administrator';

    /**
     * Ensure the protected "Administrator" system role exists and holds
     * EVERY permission currently in the catalog (view+manage). Idempotent —
     * safe to call on every deploy/seed and at first-admin registration.
     * Returns the role.
     *
     * Note what this does NOT do: it heals the role, not the catalog the role
     * is made of. On a database where the permissions table is empty it
     * happily produces an Administrator that grants nothing. Callers that
     * cannot assume a seeded catalog must run PermissionCatalog::sync()
     * first — RegisterFirstAdmin does.
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
