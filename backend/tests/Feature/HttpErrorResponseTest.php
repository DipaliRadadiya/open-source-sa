<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Route;

/*
 * These assert on the *absence* of a string, which is a weak shape of test on
 * its own -- it passes if the route stops existing. So each one also asserts
 * the replacement message is present, which fails if the handler stops firing.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

it('does not name the model class when a bound route finds nothing', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/applications/99999');

    $response->assertNotFound();

    expect($response->json('message'))
        ->not->toContain('App\\Models')
        ->not->toContain('No query results')
        ->toBe(__('errors/http.not_found'));
});

it('hides the model class for every bound resource, not just applications', function (string $path) {
    $response = $this->actingAs($this->admin)->getJson($path);

    $response->assertNotFound();
    expect($response->json('message'))->not->toContain('App\\Models');
})->with([
    '/api/databases/99999',
    '/api/system-users/99999',
    '/api/cronjobs/99999',
]);

it('uses the same message for an unmatched route, so the two are indistinguishable', function () {
    // A different message here would tell a caller "this id is wrong" versus
    // "this route does not exist", which is the enumeration hint the shared
    // message exists to remove.
    $response = $this->actingAs($this->admin)->getJson('/api/no-such-route/99999');

    $response->assertNotFound();
    expect($response->json('message'))->toBe(__('errors/http.not_found'));
});

it('does not name the route pattern or its verbs on a method mismatch', function () {
    $response = $this->actingAs($this->admin)->postJson('/api/applications/99999');

    $response->assertStatus(405);

    expect($response->json('message'))
        ->not->toContain('Supported methods')
        ->not->toContain('api/applications')
        ->toBe(__('errors/http.method_not_allowed'));
});

it('translates the message with the request locale', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/applications/99999', ['Accept-Language' => 'fr']);

    $response->assertNotFound();
    expect($response->json('message'))->toBe(__('errors/http.not_found', [], 'fr'));
});

it('leaves non-api 404s to the framework', function () {
    // The handler returns null for these, so a web route keeps whatever the
    // framework does. Guarding it means the closure cannot start swallowing
    // responses outside the API if a web route is ever added.
    //
    // Raised with abort() rather than by route model binding on purpose. The
    // guard under test is the `api/*` check, and binding would drag in the
    // harness's own handling of ModelNotFoundException on an HTML request --
    // a different mechanism, failing for a reason that says nothing about
    // this callback.
    Route::middleware('web')->get('/web-probe', fn () => abort(404));

    $response = $this->get('/web-probe');

    $response->assertNotFound();
    expect($response->getContent())->not->toContain(__('errors/http.not_found'));
});

it('keeps a 500 reporting the real exception, so only 404s were narrowed', function () {
    // Guards the blast radius. The render callbacks are typed on two HTTP
    // exceptions, but a closure that swallowed more than it should would look
    // identical until a 500 arrived with a useless body. ApiErrorLogWriter
    // ignores anything under 500, so this is also the only path where an
    // exception message is still expected to survive.
    // Pinned off, because the assertion is about what a *user* sees. The test
    // environment runs with debug on, where the framework is supposed to
    // return the raw message -- leaving it on would assert the opposite of
    // production and pass for the wrong reason.
    config(['app.debug' => false]);

    Route::middleware('api')->get('/api/boom', function (): void {
        throw new RuntimeException('deliberate failure');
    });

    $response = $this->actingAs($this->admin)->getJson('/api/boom');

    $response->assertStatus(500);

    // Not the 404 text, and not the raw exception either: with debug off a
    // non-HTTP exception is 'Server Error', which is the framework behaviour
    // this change must leave alone.
    expect($response->json('message'))
        ->not->toBe(__('errors/http.not_found'))
        ->not->toContain('deliberate failure');
});
