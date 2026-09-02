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

/** A user with a known spread of own-history rows. */
function userWithActivity(): array
{
    $user = User::factory()->create();
    $other = User::factory()->create();

    ActivityLog::create(['user_id' => $user->id, 'type' => 'user', 'action' => 'logged_in', 'properties' => []]);
    ActivityLog::create(['user_id' => $user->id, 'type' => 'user', 'action' => 'password_changed', 'properties' => []]);
    ActivityLog::create(['user_id' => $user->id, 'type' => 'cronjob', 'action' => 'created', 'properties' => ['name' => 'x']]);
    // Another user's row, which must never appear or influence the filters.
    ActivityLog::create(['user_id' => $other->id, 'type' => 'database', 'action' => 'deleted', 'properties' => []]);

    return [$user, $user->createToken('test')->plainTextToken];
}

it('filters own activity by type', function () {
    [, $token] = userWithActivity();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log?filter[type]=user');

    $response->assertOk()->assertJsonCount(2, 'activity_log');
    foreach ($response->json('activity_log') as $entry) {
        expect($entry['type'])->toBe('user');
    }
});

it('filters own activity by action', function () {
    [, $token] = userWithActivity();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log?filter[action]=created');

    $response->assertOk()->assertJsonCount(1, 'activity_log');
    $response->assertJsonPath('activity_log.0.type', 'cronjob');
    $response->assertJsonPath('activity_log.0.action', 'created');
});

it('searches own activity across type and action', function () {
    [, $token] = userWithActivity();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log?search=PASSWORD');

    $response->assertOk()->assertJsonCount(1, 'activity_log');
    $response->assertJsonPath('activity_log.0.action', 'password_changed');
});

it('never leaks another user\'s rows through a filter', function () {
    [, $token] = userWithActivity();

    // `database` rows exist, but belong to somebody else.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log?filter[type]=database');

    $response->assertOk()->assertJsonCount(0, 'activity_log');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log?search=database');

    $response->assertOk()->assertJsonCount(0, 'activity_log');
});

it('reports pagination meta alongside a filter', function () {
    [, $token] = userWithActivity();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log?filter[type]=user&per_page=10');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 2);
    $response->assertJsonPath('meta.per_page', 10);
    $response->assertJsonPath('meta.current_page', 1);
});

it('returns filter options built from the caller\'s own rows only', function () {
    [, $token] = userWithActivity();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log/filters');

    $response->assertOk();
    // `database` belongs to the other user — it must not be offered.
    expect($response->json('types'))->toBe(['cronjob', 'user']);
    expect($response->json('actions.all'))->toBe(['created', 'logged_in', 'password_changed']);
    expect($response->json('actions.user'))->toBe(['logged_in', 'password_changed']);
    expect($response->json('actions.cronjob'))->toBe(['created']);
});

it('returns empty filter options for a user with no activity', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log/filters');

    $response->assertOk();
    expect($response->json('types'))->toBe([]);
    expect($response->json('actions.all'))->toBe([]);
});

it('rejects an unsupported per_page on the own-activity log', function () {
    [, $token] = userWithActivity();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log?per_page=7')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

it('does not require admin access to view own activity filters', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/activity-log/filters')
        ->assertOk();
});

it('requires authentication to view own activity filters', function () {
    $this->getJson('/api/activity-log/filters')->assertUnauthorized();
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
