<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Models\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Gate a route by a feature permission. Usage:
     *   ->middleware('permission:system_user')          // view
     *   ->middleware('permission:system_user,manage')   // manage
     *
     * Resolves through roles + direct grants; there is no admin bypass (admins pass because
     * they hold the Administrator role).
     *
     * On a route bound to an application, an `app_`-prefixed permission is checked twice: the
     * user must hold it **and** the site type must support it. Hiding a sidebar item is not
     * authorising. Checked here so a new app route cannot forget it.
     */
    public function handle(Request $request, Closure $next, string $permission, string $ability = 'view'): Response
    {
        $user = $request->user();

        $allowed = $ability === 'manage'
            ? $user->canManage($permission)
            : $user->canView($permission);

        // Named, not a bare `abort(403)` (empty message): the panel can then say which permission
        // is missing. Uses the permission's title, or the key when the catalogue has no row.
        abort_unless($allowed, 403, __('errors/http.permission_missing', [
            'ability' => __("errors/http.ability_{$ability}"),
            'feature' => Permission::query()->where('name', $permission)->value('title') ?? $permission,
        ]));

        // 404, not 403: the screen does not exist for this site type, so no grant could help.
        $application = $request->route('application');

        if (str_starts_with($permission, 'app_') && $application instanceof Application) {
            abort_unless($application->supports($permission), 404);
        }

        return $next($request);
    }
}
