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
     * Resolves through the pure role-based model (roles + direct grants);
     * there is no admin bypass — admins pass because they hold the
     * Administrator role.
     *
     * On a route bound to an application, an `app_`-prefixed permission is
     * checked twice: the user must hold it, **and** the site type must support
     * it. Hiding the sidebar item is not authorising — a Deployment endpoint
     * on a WordPress site is reachable by anyone who types the URL, and it
     * would run against an application with no repository. The check lives
     * here rather than in each controller so a new app route cannot forget it.
     */
    public function handle(Request $request, Closure $next, string $permission, string $ability = 'view'): Response
    {
        $user = $request->user();

        $allowed = $ability === 'manage'
            ? $user->canManage($permission)
            : $user->canView($permission);

        // Named, not bare. `abort(403)` sends `{"message": ""}`, which left the
        // panel inventing its own wording for every refusal — and inventing it
        // without knowing which permission was missing, so it could only say
        // something generic where the server knew something specific.
        //
        // The permission's own title, so the sentence reads "Applications"
        // rather than `application`. Falls back to the key when the catalogue
        // has no row yet, which is better than a blank where a name goes.
        abort_unless($allowed, 403, __('errors/http.permission_missing', [
            'ability' => __("errors/http.ability_{$ability}"),
            'feature' => Permission::query()->where('name', $permission)->value('title') ?? $permission,
        ]));

        // 404, not 403: for this site the screen does not exist at all, which
        // is a different statement from "you may not". A 403 would imply the
        // user could be granted access to something that cannot exist here.
        $application = $request->route('application');

        if (str_starts_with($permission, 'app_') && $application instanceof Application) {
            abort_unless($application->supports($permission), 404);
        }

        return $next($request);
    }
}
