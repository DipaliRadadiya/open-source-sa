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
 * `bootstrap/app.php` prepends `throttle:api` to every API route, and a per-route `throttle:N,1`
 * does not replace it: both buckets must pass, so the lower always wins. A route declaring a limit
 * above the global one declares a number that never applies; the only way to notice is to make the
 * requests and watch where the 429 lands.
 *
 * These tests make that class of mistake loud. Raising the global limit does not fix it, so the
 * guard is structural rather than a number.
 */
it('has no route declaring a limit above the global one while still inside it', function () {
    $global = (int) config('server.rate_limits.api');

    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        // `throttle:api` is inside the `api` middleware GROUP, so it never appears in
        // gatherMiddleware() by name — the group does. Checking for the name directly matches nothing
        // and passes vacuously.
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
        // A database import's chunks, for the same reason as the file manager's: one large upload is
        // hundreds of requests.
        'api/databases/{database}/imports/{import}/chunk',
        // Progress feeds. Watched screens poll these for as long as the work
        // runs, so sharing one budget with the rest of the panel meant a long
        // install ended in a 429 that read as the install having failed.
        'api/applications/{application}',
        'api/applications/{application}/sidebar',
        // The file browser's archive banner, polled every 2s while a compress or extract runs. Inside
        // the global limiter it would spend 30 of the user's 180 requests a minute while the Files page
        // is open, and the 429s would land on whatever the user clicked next.
        'api/applications/{application}/files/archive-jobs',
        'api/applications/{application}/deployments/{deployment}',
        // Polled every few seconds while the Deployment screen is open, so a
        // deploy started by a push appears without a reload: the same reason
        // as the row above, in the same `progress` bucket.
        'api/applications/{application}/deployments/latest',
        'api/server/sync/{run}',
        'api/admin/panel-update/{panelUpdate}',
        // A security update waits out the dpkg lock for up to ten minutes
        // before it installs anything, so this is watched for longer than any
        // other progress feed on the panel. Sharing the interactive budget
        // would end the longest runs in a 429 — which reads as the upgrade
        // having failed, on the one screen where that is most alarming.
        'api/settings/updates/run',

        // The rest of the same class: what does a screen watch?
        //
        // `throttle:120,1` on these never applied: a per-route throttle stacks with the global one
        // rather than replacing it, so 120 loses to 180 and the interactive budget is spent anyway. A
        // number below the global limit is not headroom, and the test above cannot flag it because it
        // is not a violation.
        'api/php',
        'api/node',
        'api/setup',
        'api/databases/status/{engine}',
        'api/backups/{backup}',
        // Polled while an SFTP/FTP backup is copied to the panel for
        // download — a large archive takes minutes, as a backup does.
        'api/backups/{backup}/download/prepare',
        'api/restores/{restore}',
        'api/clones/{clone}',
        // Polled while a one-click update runs: Moodle's upgrade and Uptime
        // Kuma's rebuild take many minutes.
        'api/applications/{application}/update',
        'api/fail2ban',
        // The running-jobs list, polled by the header on every page — the one feed that is watched all
        // the time, not just while one job runs.
        'api/operations',
        // Polled while apt installs the compiler toolchain — up to
        // fifteen minutes, which the global limiter would cut short.
        'api/build-tools',

        // Not tied to a job: these two are polled every 3s for as long as their page is open, which
        // spends the interactive budget faster than anything above.
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

    // The half that matters, and it goes FIRST: an ordinary caller must not get the vendor's
    // allowance. `withHeader()` persists for the rest of the test, so asserting this after the
    // central call would measure a request still carrying the central token.
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

    // Exactly what provisioning does: the key used to be built by interpolating the bound model
    // (`toJson()` in string context), so every status change opened a fresh bucket and the limit
    // never engaged.
    $legacyBefore = '1|'.$application->fresh();

    $application->update(['status' => 'active', 'steps' => ['install' => 'done']]);

    expect($scope())->toBe($before)
        // Asserted so this test cannot become vacuous: the old expression produced a different key for
        // the same record. If a stringified model ever became stable, this line fails and the test
        // above proves nothing.
        ->and('1|'.$application->fresh())->not->toBe($legacyBefore);
});

it('keeps every progress-limited route outside the global limiter', function () {
    // `throttle:progress` allows 600/min, `throttle:api` 180. Both buckets must pass, so a progress
    // route still inside the global limiter is bounded by 180 and its own limiter never engages. The
    // test above cannot see it because no literal appears in the middleware.
    //
    // It is also the shape of a half-finished fix: swapping `throttle:N,1` for `throttle:progress`
    // and forgetting the `withoutMiddleware` line looks correct in a diff and changes nothing.
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (! in_array('api', $middleware, true)) {
            continue;
        }

        if (! in_array('throttle:progress', $middleware, true)) {
            continue;
        }

        if (! in_array('throttle:api', $route->excludedMiddleware(), true)) {
            $offenders[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['These routes ask for the progress budget but are still capped at the'],
        ['global one, so the progress limiter never applies. Add'],
        ["->withoutMiddleware('throttle:api'):"],
        $offenders,
    )));
});
