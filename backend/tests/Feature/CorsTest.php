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
