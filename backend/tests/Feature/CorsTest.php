<?php

it('allows a configured local dev origin', function () {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3000',
    ])->getJson('/api/basic-info');

    $response->assertOk();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:3000');
});

it('does not reflect an unconfigured origin', function () {
    $response = $this->withHeaders([
        'Origin' => 'https://not-allowed.example.com',
    ])->getJson('/api/basic-info');

    $response->assertOk();
    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://not-allowed.example.com');
});

it('lets the frontend read how long a throttled request should wait', function () {
    // The upload screen is on another origin; unexposed, the browser hides
    // Retry-After from it and it cannot say when to try again.
    expect(config('cors.exposed_headers'))->toContain('Retry-After');
});
