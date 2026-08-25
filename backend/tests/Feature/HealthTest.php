<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('answers without authentication', function () {
    // An update verifies itself by calling this on localhost right after
    // restarting services. There is no session or token at that moment, so
    // requiring one would mean the check could only ever fail.
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('health.status', 'ok');
});

it('reports the running version so an update can confirm the new code answered', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk();
    expect($response->json('health.version'))->not->toBeNull();
});

it('exposes nothing beyond status and version', function () {
    $response = $this->getJson('/api/health');

    expect(array_keys($response->json('health')))->toBe(['status', 'version']);
});

it('uses an isolated rate-limit bucket for update health retries', function () {
    $route = Route::getRoutes()->match(Request::create('/api/health', 'GET'));
    $middleware = $route->gatherMiddleware();

    expect($middleware)
        ->toContain('throttle:60,1')
        ->not->toContain('throttle:api');
});

it('allows all thirty update health retries', function () {
    foreach (range(1, 30) as $attempt) {
        $this->getJson('/api/health')->assertOk();
    }
});
