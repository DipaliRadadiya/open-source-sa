<?php

use App\Http\Middleware\CentralSystemGuard;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\SetLocale;
use App\Services\Admin\ApiErrorLogWriter;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Pure API backend, no web login route to redirect guests to.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
        // statefulApi() enables Sanctum cookie/session auth for requests from
        // SANCTUM_STATEFUL_DOMAINS, alongside Bearer token auth (both work).
        $middleware->statefulApi();
        // CentralSystemGuard is GLOBAL, not a group or route layer.
        //
        // It has to run before `auth:sanctum`, and being first in the `api`
        // group does not achieve that: `Authenticate` sits in Laravel's
        // middleware priority list, so the sorter pulls it ahead of anything
        // that is not also in that list. Registering the guard in the priority
        // list did not hold either. Global middleware runs before route and
        // group middleware are dispatched at all, so the ordering stops being
        // something to keep in step.
        //
        // Safe to run on every request precisely because it is inert unless a
        // valid central token is presented — see the middleware itself.
        $middleware->prepend(CentralSystemGuard::class);

        $middleware->api(prepend: ['throttle:api'], append: [SetLocale::class]);

        // The panel takes itself down to update, and these are the routes that
        // have to keep answering while it does.
        //
        // `artisan down` is step two of an update, so from that moment every
        // API call returned 503 — including the progress feed the update page
        // polls, and the button itself. The page could not show the run it
        // exists to show, and pressing update during one answered "Couldn't
        // start the update" when the truth was "step 12 of 16". That is the
        // single most confusing thing about this feature, and it is this line.
        //
        // `api/health` is here for a sharper reason: the release flow verifies
        // *before* leaving maintenance (swap, restart, verify, maintenance_off
        // — the order is deliberate, so a bad release rolls back while users
        // are still seeing the maintenance page). Its verification curls
        // /api/health, so with health behind the maintenance gate that flow
        // could never pass its own check and would roll back every time.
        //
        // Declared here rather than as `artisan down --except=…` so it cannot
        // be set in one of the two update scripts and forgotten in the other.
        // Everything else still returns 503, which is the point of maintenance
        // mode: no writes reach a half-updated panel.
        $middleware->preventRequestsDuringMaintenance(except: [
            'api/health',
            'api/admin/panel-update',
            'api/admin/panel-update/*',
        ]);

        $middleware->alias([
            'permission' => CheckPermission::class,
            'central' => CentralSystemGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (Throwable $exception): void {
            app(ApiErrorLogWriter::class)->record($exception, request());
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
