<?php

namespace App\Actions\Admin;

use App\Models\Role;
use App\Services\ActivityLogger;
use App\Services\PermissionResolver;
use Illuminate\Support\Str;

class CreateRole
{
    public function __construct(
        private PermissionResolver $permissionResolver,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{name: string, description: ?string, permissions?: array<int, array{level: string, name: string, view: bool, manage: bool}>}  $data
     */
    public function execute(array $data): Role
    {
        $role = Role::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'description' => $data['description'] ?? null,
        ]);

        if (! empty($data['permissions'])) {
            $role->permissions()->sync($this->permissionResolver->resolve($data['permissions']));
        }

        $this->activityLogger->log('role.created', $role, ['name' => $role->name]);

        return $role;
    }
}
