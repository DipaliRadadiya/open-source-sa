<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

it('issues login tokens that expire after the configured number of days', function () {
    $user = User::factory()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);

    $response = $this->postJson('/api/auth/login', ['username' => 'jdoe', 'password' => 'Password123']);
    $token = $response->json('token');
    $tokenId = explode('|', $token)[0];

    $record = PersonalAccessToken::find($tokenId);

    expect($record->expires_at)->not->toBeNull();
    expect((int) round(now()->floatDiffInDays($record->expires_at)))->toBe(10);
});

it('rejects an expired token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('default', ['*'], now()->subDay())->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});
