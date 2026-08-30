<?php

use App\Models\Application;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Server\CentralTokenManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
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
        // Unauthenticated liveness probe with its own 60/min bucket: an update
        // calls it on localhost after switching releases, and sharing the
        // global bucket with browser polling would make the check fail exactly
        // when it is needed. Deliberate — see routes/api/health.php.
        'api/health',
        'api/applications/{application}/files/uploads/space',
        'api/applications/{application}/files/uploads',
        'api/applications/{application}/files/uploads/{uploadId}',
        'api/applications/{application}/files/uploads/{uploadId}/finalize',
        // Progress feeds. Watched screens poll these for as long as the work
        // runs, so sharing one budget with the rest of the panel meant a long
        // install ended in a 429 that read as the install having failed.
        'api/applications/{application}',
        'api/applications/{application}/sidebar',
        'api/applications/{application}/deployments/{deployment}',
        'api/server/sync/{run}',
        'api/admin/panel-update/{panelUpdate}',

        // The rest of the same class, found by auditing for "what does a
        // screen watch?" rather than by waiting for the next 429 report.
        //
        // The last three previously declared `throttle:120,1`, which never
        // applied: a per-route throttle stacks with the global one rather than
        // replacing it, so 120 lost to 180 and they spent the interactive
        // budget anyway. A number below the global limit is not headroom, and
        // the test above cannot flag it because it is not a violation.
        'api/php',
        'api/node',
        'api/setup',
        'api/databases/status/{engine}',
        'api/backups/{backup}',
        'api/restores/{restore}',
        'api/clones/{clone}',
        'api/fail2ban',

        // Not tied to a job at all: these two are polled every 3s for as long
        // as their page is open, which spends the interactive budget faster
        // than anything above. Reported by the frontend rather than found by a
        // 429, which is the way round we asked for.
        'api/server/metrics/live',
        'api/services',
    ]);
});

it('gives the central panel its own budget, and no one else', function () {
    // Both assertions below compare against config, so they would both hold
    // trivially if the two limits were configured to the same number — and
    // this suite reads the real .env.
    expect((int) config('server.rate_limits.central'))
        ->not->toBe((int) config('server.rate_limits.api'));

    // The half that matters, and it goes FIRST: an ordinary caller must not be
    // handed the vendor's allowance. `withHeader()` persists for the rest of
    // the test, so asserting this after the central call measured a request
    // that was still carrying the central token — it read 3000 and would have
    // passed had the branch been wrong in exactly the way this guards against.
    $user = User::factory()->create();

    expect($this->actingAs($user)->getJson('/api/basic-info')->headers->get('X-RateLimit-Limit'))
        ->toBe((string) config('server.rate_limits.api'));

    // The real token, through the real guard — not the attribute set by hand,
    // which would prove only that the branch reads what the test wrote.
    $token = app(CentralTokenManager::class)->enable()['central_token'];

    expect($this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/basic-info')
        ->headers->get('X-RateLimit-Limit'))
        ->toBe((string) config('server.rate_limits.central'));
});

it('keys the progress limiter on identity, not on the record being polled', function () {
    $application = Application::factory()->create(['status' => 'provisioning']);

    $scope = function () use ($application): string {
        $request = Request::create("/api/applications/{$application->id}");
        $route = new RoutingRoute('GET', '/api/applications/{application}', fn () => null);
        $route->bind($request);
        $route->setParameter('application', $application->fresh());
        $request->setRouteResolver(fn () => $route);

        $method = new ReflectionMethod(AppServiceProvider::class, 'routeScope');

        return $method->invoke(app(AppServiceProvider::class, ['app' => app()]), $request);
    };

    $before = $scope();

    // Exactly what provisioning does, and the reason this test exists: the key
    // used to be built by interpolating the bound model, which is `toJson()` in
    // string context. Every status change produced a different key, so every
    // poll opened a fresh bucket and the limit never engaged — a limiter that
    // reads like protection and is not one.
    $legacyBefore = '1|'.$application->fresh();

    $application->update(['status' => 'active', 'steps' => ['install' => 'done']]);

    expect($scope())->toBe($before)
        // Asserted so this test cannot quietly become vacuous: the old
        // expression really did produce a different key for the same record,
        // which is the whole defect. If a future change made a stringified
        // model stable, this line fails and the test above stops proving
        // anything worth proving.
        ->and('1|'.$application->fresh())->not->toBe($legacyBefore);
});
