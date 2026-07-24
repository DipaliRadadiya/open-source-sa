<?php

use App\Models\User;

it('logs in via session cookie for stateful requests, alongside issuing a token', function () {
    $user = User::factory()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);

    $response = $this->withHeader('Origin', 'http://localhost:3000')
        ->postJson('/api/auth/login', [
            'username' => 'jdoe',
            'password' => 'Password123',
        ]);

    $response->assertOk()->assertJsonStructure(['user', 'token']);
    $this->assertAuthenticatedAs($user, 'web');
});

it('still authenticates via Bearer token for non-browser clients', function () {
    $user = User::factory()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me');

    $response->assertOk()->assertJsonPath('user.username', 'jdoe');
});

it('logs out a cookie-session-authenticated request cleanly', function () {
    $user = User::factory()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);

    $this->withHeader('Origin', 'http://localhost:3000')
        ->postJson('/api/auth/login', ['username' => 'jdoe', 'password' => 'Password123']);

    $this->assertAuthenticatedAs($user, 'web');

    $this->withHeader('Origin', 'http://localhost:3000')
        ->postJson('/api/auth/logout')
        ->assertNoContent();

    $this->assertGuest('web');
});

it('logs out a token-authenticated request without touching an unrelated session', function () {
    $user = User::factory()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);
    $token = $user->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/logout')
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});
