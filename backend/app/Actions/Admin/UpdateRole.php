<?php

namespace App\Actions\Admin;

use App\Models\Role;
use App\Services\ActivityLogger;
use App\Services\PermissionResolver;
use Illuminate\Support\Str;

class UpdateRole
{
    public function __construct(
        private PermissionResolver $permissionResolver,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{name: string, description: ?string, permissions?: array<int, array{level: string, name: string, view: bool, manage: bool}>}  $data
     */
    public function execute(Role $role, array $data): Role
    {
        $role->update([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'description' => $data['description'] ?? null,
        ]);

        if (array_key_exists('permissions', $data)) {
            $role->permissions()->sync($this->permissionResolver->resolve($data['permissions']));
        }

        $this->activityLogger->log('role.updated', $role, ['name' => $role->name]);

        return $role;
    }
}
