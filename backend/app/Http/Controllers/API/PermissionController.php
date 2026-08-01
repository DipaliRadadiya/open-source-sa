<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckPermissionRequest;
use App\Http\Requests\ListPermissionsRequest;
use App\Models\Application;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function index(ListPermissionsRequest $request): JsonResponse
    {
        return response()->json([
            'permissions' => $this->visiblePermissions(
                $request->user(),
                $request->string('level')->toString() ?: null,
                $request->filled('application_id')
                    ? Application::find($request->integer('application_id'))
                    : null,
            ),
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
     * With an application, a second filter is applied on top: what that site
     * type can actually do. The two answer different questions and both have
     * callers — the role form needs every item, one site's sidebar needs its
     * own.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visiblePermissions(User $user, ?string $level, ?Application $application = null): array
    {
        $query = Permission::query()->orderBy('order');

        if ($level) {
            $query->where('level', $level);
        }

        $permissions = $query->get();

        // The second filter. Granting `app_deployment` gives it on every site,
        // but a WordPress install has no repository — the screen would be about
        // nothing. Applied here rather than in the frontend so a new site type
        // costs one class and no frontend change, and so there is one answer
        // rather than fourteen special cases spread across screens.
        //
        // Hide rather than grey: there is nothing the user could do to enable
        // PHP settings on a static site, so a disabled row is only noise.
        // Greying is for things they can fix.
        if ($application !== null) {
            $features = $application->features();

            $permissions = $permissions->filter(
                fn (Permission $permission) => $permission->level !== 'application'
                    || in_array($permission->name, $features, true)
            );
        }

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
