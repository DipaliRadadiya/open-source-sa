<?php

use Illuminate\Support\Facades\Route;

/**
 * The global `api` limiter and the per-route ones have to agree.
 *
 * `bootstrap/app.php` prepends `throttle:api` to every API route, and a
 * per-route `throttle:N,1` does not replace it — both buckets must pass, so
 * the lower always wins. A route that declares a limit above the global one
 * is therefore declaring a number that never applies, and the only way to
 * notice is to make the requests and watch where the 429 lands.
 *
 * That is exactly how the resumable-upload endpoints shipped: 1200/min and
 * 240/min on paper, 120/min in fact, so a large upload competed with the
 * panel's own polling for one budget and stalled partway through.
 *
 * These tests make the class of mistake loud. Raising the global limit does
 * not fix it — it only moves where it bites — so the guard is structural
 * rather than a number.
 */
it('has no route declaring a limit above the global one while still inside it', function () {
    $global = (int) config('server.rate_limits.api');

    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        // `throttle:api` is inside the `api` middleware GROUP, so it never
        // appears in gatherMiddleware() by name — the group does. Checking for
        // the name directly matched nothing and made this test pass over every
        // route in the application while proving exactly nothing.
        if (! in_array('api', $middleware, true)) {
            continue;
        }

        // A route that dropped the global limiter is free to declare whatever
        // it needs — that is the supported way to ask for headroom, and it is
        // recorded as an exclusion rather than an absence.
        if (in_array('throttle:api', $route->excludedMiddleware(), true)) {
            continue;
        }

        foreach ($middleware as $layer) {
            if (! preg_match('/^throttle:(\d+),\d+$/', (string) $layer, $matches)) {
                continue;
            }

            if ((int) $matches[1] > $global) {
                $offenders[] = sprintf(
                    '%s %s declares %s/min but is capped at %d by throttle:api',
                    implode('|', $route->methods()),
                    $route->uri(),
                    $matches[1],
                    $global,
                );
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['A per-route throttle above the global one never takes effect.'],
        ['Either lower it, or add ->withoutMiddleware(\'throttle:api\') as the'],
        ['upload endpoints and the deploy webhook do:'],
        $offenders,
    )));
});

it('keeps the routes that opted out of the global limiter deliberate', function () {
    $exempt = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('api', $route->gatherMiddleware(), true)) {
            continue;
        }

        if (in_array('throttle:api', $route->excludedMiddleware(), true)) {
            $exempt[] = $route->uri();
        }
    }

    // Listed rather than counted: an exemption is a route the global limit no
    // longer bounds, so a new one should be a decision someone made on
    // purpose, not something that arrived with a copied line.
    expect(array_values(array_unique($exempt)))->toEqualCanonicalizing([
        'api/webhooks/deploy/{identifier}',
        'api/applications/{application}/files/uploads/space',
        'api/applications/{application}/files/uploads',
        'api/applications/{application}/files/uploads/{uploadId}',
        'api/applications/{application}/files/uploads/{uploadId}/finalize',
    ]);
});
