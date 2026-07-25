<?php

use App\Models\ActivityLog;
use App\Models\User;

it('shows a user only their own activity, not other users\' activity', function () {
    $userA = User::factory()->create(['username' => 'alice', 'password' => bcrypt('Password123')]);
    $userB = User::factory()->create(['username' => 'bob', 'password' => bcrypt('Password123')]);

    $this->postJson('/api/auth/login', ['username' => 'alice', 'password' => 'Password123']);
    $this->postJson('/api/auth/login', ['username' => 'bob', 'password' => 'Password123']);

    $tokenA = $userA->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/activity-log');

    $response->assertOk();
    $expectedCount = ActivityLog::where('user_id', $userA->id)->count();
    expect($expectedCount)->toBeGreaterThan(0);
    $response->assertJsonCount($expectedCount, 'activity_log');
});

it('omits the redundant user field from own-activity entries', function () {
    $user = User::factory()->admin()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/users', userPayload(['username' => 'newuser']));

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log');

    $response->assertOk();
    expect($response->json('activity_log'))->not->toBeEmpty();
    foreach ($response->json('activity_log') as $entry) {
        expect($entry)->not->toHaveKey('user');
    }
});

it('requires authentication to view own activity log', function () {
    $this->getJson('/api/activity-log')->assertUnauthorized();
});

it('does not require admin access to view own activity log', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log')
        ->assertOk();
});
