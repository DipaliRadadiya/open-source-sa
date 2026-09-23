<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * `throttle:N,M` counted per user, not per route: every numerically throttled
 * route shared one counter. Found live (2026-09-23) — creating and testing a
 * storage destination used up "Run backup now" (throttle:6,1), which answered
 * "Too Many Attempts" to its first click.
 */

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'throttle:2,1'])->group(function () {
        Route::get('/api/_throttle/a', fn () => 'a');
        Route::get('/api/_throttle/b/{id}', fn () => 'b');
    });

    $this->user = User::factory()->create();
});

it('gives each route its own counter', function () {
    $this->actingAs($this->user);

    $this->getJson('/api/_throttle/a')->assertOk();
    $this->getJson('/api/_throttle/a')->assertOk();
    $this->getJson('/api/_throttle/a')->assertTooManyRequests();

    // Route a being exhausted must not touch route b.
    $this->getJson('/api/_throttle/b/1')->assertOk();
});

it('still limits one action, whatever its parameters', function () {
    $this->actingAs($this->user);

    $this->getJson('/api/_throttle/b/1')->assertOk();
    $this->getJson('/api/_throttle/b/2')->assertOk();
    // `/b/1` and `/b/2` are one action with one limit.
    $this->getJson('/api/_throttle/b/3')->assertTooManyRequests();
});

it('keeps separate users separate', function () {
    $other = User::factory()->create();

    $this->actingAs($this->user)->getJson('/api/_throttle/a')->assertOk();
    $this->actingAs($this->user)->getJson('/api/_throttle/a')->assertOk();
    $this->actingAs($this->user)->getJson('/api/_throttle/a')->assertTooManyRequests();

    $this->actingAs($other)->getJson('/api/_throttle/a')->assertOk();
});
