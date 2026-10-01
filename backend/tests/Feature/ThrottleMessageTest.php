<?php

use App\Models\User;

/*
 * Laravel's throttle answers "Too Many Attempts." in English whatever the
 * user's language, and says nothing about when to try again. Found on the
 * login form 2026-10-01.
 */

function exhaustLogin(string $username, ?string $language = null)
{
    $headers = $language ? ['Accept-Language' => $language] : [];

    // `login` allows five a minute per username and address.
    foreach (range(1, 5) as $_) {
        test()->postJson('/api/auth/login', ['username' => $username, 'password' => 'wrong-password'], $headers)->assertStatus(422);
    }

    return test()->postJson('/api/auth/login', ['username' => $username, 'password' => 'wrong-password'], $headers);
}

it('says in the user\'s language when to try again', function () {
    User::factory()->create(['username' => 'throttled']);

    $response = exhaustLogin('throttled', 'es')->assertStatus(429);
    $seconds = (int) $response->headers->get('Retry-After');

    expect($seconds)->toBeGreaterThan(0)
        ->and($response->json('message'))->toBe(trans_choice('errors/http.too_many_requests', $seconds, ['seconds' => $seconds], 'es'))
        ->and($response->json('message'))->toContain('Demasiados intentos')
        ->and($response->json('message'))->not->toContain('Too Many Attempts');
});

it('keeps the rate-limit headers and nothing else', function () {
    $response = exhaustLogin('nobody-at-all')->assertStatus(429);

    expect($response->headers->has('Retry-After'))->toBeTrue()
        ->and($response->headers->has('X-RateLimit-Limit'))->toBeTrue()
        ->and(array_keys($response->json()))->toBe(['message']);
});

it('has the sentence in every locale', function () {
    foreach (config('app.available_locales', ['en']) as $locale) {
        expect(trans_choice('errors/http.too_many_requests', 30, ['seconds' => 30], $locale))
            ->not->toBe('errors/http.too_many_requests')
            ->toContain('30');
    }
});
