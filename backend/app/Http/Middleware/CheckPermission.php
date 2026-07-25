<?php

namespace App\Http\Middleware;

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
     */
    public function handle(Request $request, Closure $next, string $permission, string $ability = 'view'): Response
    {
        $user = $request->user();

        $allowed = $ability === 'manage'
            ? $user->canManage($permission)
            : $user->canView($permission);

        abort_unless($allowed, 403);

        return $next($request);
    }
}
