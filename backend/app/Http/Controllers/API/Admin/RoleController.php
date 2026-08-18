<?php

namespace App\Http\Controllers\API\Admin;

use App\Actions\Admin\CreateRole;
use App\Actions\Admin\DeleteRole;
use App\Actions\Admin\UpdateRole;
use App\Enums\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListRolesRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Role;
use App\Support\ListSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(ListRolesRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search', ''));

        $roles = Role::query()
            ->with('permissions')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                // Grouped so the OR cannot escape across the whole query if a
                // filter is added beside it later.
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('description', 'like', $like));
            });

        $roles = ListSort::apply($roles, $request->validated('sort'), ListRolesRequest::SORTS, 'asc')
            ->paginate($request->validated('per_page', ListRolesRequest::PER_PAGE));

        return response()->json([
            'roles' => array_map(fn (Role $role) => $this->format($role), $roles->items()),
            'meta' => [
                'current_page' => $roles->currentPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
                'last_page' => $roles->lastPage(),
            ],
        ]);
    }

    public function store(StoreRoleRequest $request, CreateRole $action): JsonResponse
    {
        $role = $action->execute($request->validated());

        return response()->json(['role' => $this->format($role->load('permissions'))], 201);
    }

    public function update(UpdateRoleRequest $request, Role $role, UpdateRole $action): JsonResponse
    {
        $this->guardSystemRole($role);

        $role = $action->execute($role, $request->validated());

        return response()->json(['role' => $this->format($role->load('permissions'))]);
    }

    public function destroy(Role $role, DeleteRole $action): JsonResponse
    {
        $this->guardSystemRole($role);

        $action->execute($role);

        return response()->json(null, 204);
    }

    /**
     * System roles (e.g. Administrator) are managed by the seeder only —
     * they can't be renamed, permission-edited, or deleted via the API.
     */
    private function guardSystemRole(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => [__('role.system_immutable')],
            ]);
        }
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
            'is_system' => $role->is_system,
            'description' => $role->description,
            'permissions' => $role->permissions->map(function ($permission) {
                $view = (bool) $permission->pivot->view;
                $manage = (bool) $permission->pivot->manage;

                return [
                    'level' => $permission->level,
                    'name' => $permission->name,
                    // localizedTitle(), not the raw column: the catalog this
                    // form is rendered against localizes it, so returning the
                    // stored English here left the role screen in English in
                    // all eight locales while the sidebar beside it translated.
                    'title' => $permission->localizedTitle(),
                    // One field the form can bind a three-way control to.
                    // The booleans stay for anything already reading them.
                    'access' => AccessLevel::fromGrant($view, $manage)->value,
                    'permissions' => [
                        'view' => $view,
                        'manage' => $manage,
                    ],
                ];
            })->all(),
            'created_at' => $role->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $role->created_at?->diffForHumans(),
        ];
    }
}
