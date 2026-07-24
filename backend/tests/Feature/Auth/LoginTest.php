<?php

use App\Models\User;

it('logs in with correct username and password', function () {
    User::factory()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);

    $response = $this->postJson('/api/auth/login', [
        'username' => 'jdoe',
        'password' => 'Password123',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.username', 'jdoe')
        ->assertJsonStructure(['user' => ['id', 'name', 'username', 'role'], 'token']);
});

it('rejects login with wrong password', function () {
    User::factory()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);

    $response = $this->postJson('/api/auth/login', [
        'username' => 'jdoe',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable();
});

it('rejects login for unknown username', function () {
    $response = $this->postJson('/api/auth/login', [
        'username' => 'nobody',
        'password' => 'Password123',
    ]);

    $response->assertUnprocessable();
});

it('logs out and revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/logout');

    $response->assertNoContent();
    expect($user->tokens()->count())->toBe(0);
});

it('returns the authenticated user via me', function () {
    $user = User::factory()->create(['username' => 'jdoe']);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me');

    $response->assertOk()->assertJsonPath('user.username', 'jdoe');
});

it('rejects unauthenticated access to me', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});
