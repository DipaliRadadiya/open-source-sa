<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckPermissionRequest;
use App\Http\Requests\ListPermissionsRequest;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function index(ListPermissionsRequest $request): JsonResponse
    {
        return response()->json([
            'permissions' => $this->visiblePermissions($request->user(), $request->string('level')->toString() ?: null),
        ]);
    }

    public function check(CheckPermissionRequest $request): JsonResponse
    {
        return response()->json([
            'permissions' => $this->visiblePermissions($request->user(), $request->string('level')->toString()),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function visiblePermissions(User $user, ?string $level): array
    {
        $query = Permission::query()->orderBy('order');

        if ($level) {
            $query->where('level', $level);
        }

        $permissions = $query->get();

        // Effective grant = direct grant OR role grant (union), admin bypasses both.
        $directGrants = $user->isAdmin() ? [] : $user->permissions()->get()->keyBy('id');
        $roleGrants = (! $user->isAdmin() && $user->role_id)
            ? $user->assignedRole->permissions()->get()->keyBy('id')
            : collect();

        return $permissions
            ->map(function (Permission $permission) use ($user, $directGrants, $roleGrants) {
                if ($user->isAdmin()) {
                    $view = true;
                    $manage = true;
                } else {
                    $direct = $directGrants->get($permission->id);
                    $viaRole = $roleGrants->get($permission->id);
                    $view = (bool) ($direct?->pivot->view ?? false) || (bool) ($viaRole?->pivot->view ?? false);
                    $manage = (bool) ($direct?->pivot->manage ?? false) || (bool) ($viaRole?->pivot->manage ?? false);
                }

                return [
                    'level' => $permission->level,
                    'sub_level' => $permission->sub_level,
                    'name' => $permission->name,
                    'title' => $permission->title,
                    'icon' => $permission->icon,
                    'url' => $permission->url,
                    'permissions' => ['view' => $view, 'manage' => $manage],
                ];
            })
            ->filter(fn (array $item) => $item['permissions']['view'] || $item['permissions']['manage'])
            ->values()
            ->all();
    }
}
