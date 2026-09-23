<?php

namespace App\Http\Middleware;

use App\Exceptions\Server\Application\SystemUserMissingException;
use App\Http\Controllers\API\Server\ApplicationController;
use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every application route for a site whose system user is missing,
 * in one place.
 *
 * The code behind those routes — about a hundred call sites — reads
 * `$application->systemUser->username` or builds paths from the user's home,
 * and the schema says it may: `system_user_id` is a required foreign key. A
 * row that broke that anyway (imported data, a database without enforced
 * foreign keys) answered every screen with a 500 on "username on null".
 * Guarding each call site would be a hundred chances to miss one.
 *
 * Opening the site and deleting it stay allowed: a broken site has to be
 * visible, and removable, from the panel.
 */
class EnsureApplicationHasSystemUser
{
    /** Controller methods that must keep working for a broken site. */
    private const ALLOWED = [
        ApplicationController::class.'@show',
        ApplicationController::class.'@destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $application = $route?->parameter('application');

        // Bound or not yet bound, depending on where this runs relative to
        // SubstituteBindings. An id that matches nothing is left for the
        // binding to answer with its own 404.
        if ($application !== null && ! $application instanceof Application) {
            $application = Application::query()->find($application);
        }

        if ($application instanceof Application
            && $application->systemUser === null
            && ! in_array($route->getActionName(), self::ALLOWED, true)) {
            throw new SystemUserMissingException((string) $application->name);
        }

        return $next($request);
    }
}
