<?php

use App\Http\Middleware\CentralSystemGuard;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\SetLocale;
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

        $middleware->alias([
            'permission' => CheckPermission::class,
            'central' => CentralSystemGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (Throwable $exception): void {
            app(\App\Services\Admin\ApiErrorLogWriter::class)->record($exception, request());
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
