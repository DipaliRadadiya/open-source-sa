<?php

namespace App\Http\Controllers\API\Admin;

use App\Actions\Admin\CreateRole;
use App\Actions\Admin\DeleteRole;
use App\Actions\Admin\UpdateRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()->with('permissions')->get();

        return response()->json([
            'roles' => $roles->map(fn (Role $role) => $this->format($role))->all(),
        ]);
    }

    public function store(StoreRoleRequest $request, CreateRole $action): JsonResponse
    {
        $role = $action->execute($request->validated());

        return response()->json(['role' => $this->format($role->load('permissions'))], 201);
    }

    public function update(UpdateRoleRequest $request, Role $role, UpdateRole $action): JsonResponse
    {
        $role = $action->execute($role, $request->validated());

        return response()->json(['role' => $this->format($role->load('permissions'))]);
    }

    public function destroy(Role $role, DeleteRole $action): JsonResponse
    {
        $action->execute($role);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'permissions' => $role->permissions->map(fn ($permission) => [
                'level' => $permission->level,
                'name' => $permission->name,
                'title' => $permission->title,
                'permissions' => [
                    'view' => (bool) $permission->pivot->view,
                    'manage' => (bool) $permission->pivot->manage,
                ],
            ])->all(),
            'created_at' => $role->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $role->created_at?->diffForHumans(),
        ];
    }
}
