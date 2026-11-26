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
    // A different message would tell a caller "this id is wrong" versus "this route does not
    // exist": the enumeration hint the shared message removes.
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
    // The handler returns null for these, so a web route keeps the framework's own response.
    //
    // Raised with abort() on purpose: route model binding would test the framework's
    // ModelNotFoundException handling instead of the `api/*` check.
    Route::middleware('web')->get('/web-probe', fn () => abort(404));

    $response = $this->get('/web-probe');

    $response->assertNotFound();
    expect($response->getContent())->not->toContain(__('errors/http.not_found'));
});

it('keeps a 500 reporting the real exception, so only 404s were narrowed', function () {
    // A closure swallowing more than 404s would look identical until a 500 arrived with a
    // useless body. Debug is pinned off: the test environment runs with it on and the framework
    // then returns the raw message, the opposite of what a user sees in production.
    config(['app.debug' => false]);

    Route::middleware('api')->get('/api/boom', function (): void {
        throw new RuntimeException('deliberate failure');
    });

    $response = $this->actingAs($this->admin)->getJson('/api/boom');

    $response->assertStatus(500);

    // Neither the 404 text nor the raw exception: with debug off a non-HTTP exception is
    // 'Server Error', framework behaviour that must stay as it is.
    expect($response->json('message'))
        ->not->toBe(__('errors/http.not_found'))
        ->not->toContain('deliberate failure');
});
