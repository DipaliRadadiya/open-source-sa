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
     * Pure role-based (no admin bypass): effective grant per permission is
     * the deduped OR-union across ALL the user's assigned roles. A permission
     * is included only if view or manage is granted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visiblePermissions(User $user, ?string $level): array
    {
        $query = Permission::query()->orderBy('order');

        if ($level) {
            $query->where('level', $level);
        }

        $permissions = $query->get();

        // Build effective {view, manage} per permission id, merged across
        // direct grants + every role the user holds (dedup by OR).
        $effective = [];
        $merge = function ($grants) use (&$effective) {
            foreach ($grants as $grant) {
                $current = $effective[$grant->id] ?? ['view' => false, 'manage' => false];
                $effective[$grant->id] = [
                    'view' => $current['view'] || (bool) $grant->pivot->view || (bool) $grant->pivot->manage,
                    'manage' => $current['manage'] || (bool) $grant->pivot->manage,
                ];
            }
        };

        $user->roles()->with('permissions')->get()->each(fn ($role) => $merge($role->permissions));

        return $permissions
            ->map(function (Permission $permission) use ($effective) {
                $grant = $effective[$permission->id] ?? ['view' => false, 'manage' => false];

                return [
                    'level' => $permission->level,
                    'sub_level' => $permission->sub_level,
                    'sub_level_title' => $permission->localizedSubLevel(),
                    'name' => $permission->name,
                    'title' => $permission->localizedTitle(),
                    'icon' => $permission->icon,
                    'url' => $permission->url,
                    'permissions' => ['view' => $grant['view'], 'manage' => $grant['manage']],
                ];
            })
            ->filter(fn (array $item) => $item['permissions']['view'] || $item['permissions']['manage'])
            ->values()
            ->all();
    }
}
