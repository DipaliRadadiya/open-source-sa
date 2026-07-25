<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\SetLocale;
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
        $middleware->api(prepend: ['throttle:api'], append: [SetLocale::class]);
        $middleware->alias(['permission' => CheckPermission::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
