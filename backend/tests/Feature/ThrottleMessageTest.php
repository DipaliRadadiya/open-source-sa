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

describe('the login limit (bug #2)', function () {
    it('stops one address trying many usernames', function () {
        // Four tries on each of five names is under the per-name-and-address
        // limit every time. Changing the name used to be a fresh budget.
        foreach (range(1, 5) as $n) {
            foreach (range(1, 4) as $_) {
                test()->postJson('/api/auth/login', ['username' => "guess{$n}", 'password' => 'wrong'])->assertStatus(422);
            }
        }

        test()->postJson('/api/auth/login', ['username' => 'guess6', 'password' => 'wrong'])->assertStatus(429);
    });

    it('stops one username being tried from many addresses', function () {
        foreach (range(1, 10) as $n) {
            test()->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$n}"])
                ->postJson('/api/auth/login', ['username' => 'Admin', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        // Case does not make it a different account.
        test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/auth/login', ['username' => 'admin', 'password' => 'wrong'])
            ->assertStatus(429);
    });

    it('still lets someone in who mistyped a few times', function () {
        User::factory()->create(['username' => 'returning', 'password' => 'Correct-Horse-9']);

        foreach (range(1, 3) as $_) {
            test()->postJson('/api/auth/login', ['username' => 'returning', 'password' => 'wrong'])->assertStatus(422);
        }

        test()->postJson('/api/auth/login', ['username' => 'returning', 'password' => 'Correct-Horse-9'])->assertOk();
    });
});
