<?php

use App\Models\Role;
use App\Models\User;

it('logs the first-admin registration', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'First Admin', 'username' => 'firstadmin',
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ]);

    $admin = User::firstWhere('username', 'firstadmin');
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log');

    $response->assertOk()
        ->assertJsonPath('activity_log.0.type', 'user')
        ->assertJsonPath('activity_log.0.action', 'registered')
        ->assertJsonPath('activity_log.0.description', 'Registered as the first administrator');
});

it('logs login events', function () {
    $user = User::factory()->admin()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);
    $this->postJson('/api/auth/login', ['username' => 'jdoe', 'password' => 'Password123']);

    $token = $user->createToken('test')->plainTextToken;
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log');

    $response->assertOk()->assertJsonFragment(['action' => 'logged_in']);
});

it('logs admin creating a user, attributed to the admin not the new user', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/users', userPayload(['username' => 'newuser']));

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log');

    $response->assertOk()
        ->assertJsonPath('activity_log.0.action', 'created')
        ->assertJsonPath('activity_log.0.description', 'Created user newuser')
        ->assertJsonPath('activity_log.0.user.username', $admin->username);
});

it('translates descriptions based on the viewing admin locale, not the actor locale', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/users', userPayload(['username' => 'newuser']));

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept-Language' => 'es'])
        ->getJson('/api/admin/activity-log');

    $response->assertOk()
        ->assertJsonPath('activity_log.0.description', 'Creó el usuario newuser');
});

it('denies non-admins from viewing the activity log', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log')
        ->assertForbidden();
});

it('filters the activity log by user', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['username' => 'other', 'password' => bcrypt('Password123')]);
    $adminToken = $admin->createToken('test')->plainTextToken;

    $this->postJson('/api/auth/login', ['username' => 'other', 'password' => 'Password123']);

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/admin/activity-log?filter[user_id]='.$other->id);

    expect($response->json('activity_log'))->not->toBeEmpty();
    foreach ($response->json('activity_log') as $entry) {
        expect($entry['user']['id'])->toBe($other->id);
    }
});

it('filters the activity log by action', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/users', userPayload(['username' => 'newuser']));

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log?filter[action]=created');

    expect($response->json('activity_log'))->not->toBeEmpty();
    foreach ($response->json('activity_log') as $entry) {
        expect($entry['action'])->toBe('created');
    }
});

it('searches the activity log by acting username', function () {
    $admin = User::factory()->admin()->create(['username' => 'searchableadmin']);
    $other = User::factory()->admin()->create(['username' => 'unrelatedadmin']);
    $adminToken = $admin->createToken('test')->plainTextToken;
    $otherToken = $other->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson('/api/admin/users', userPayload(['name' => 'A', 'username' => 'usera']));
    $this->withHeader('Authorization', "Bearer {$otherToken}")->postJson('/api/admin/users', userPayload(['name' => 'B', 'username' => 'userb']));

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/admin/activity-log?search=searchableadmin');

    expect($response->json('activity_log'))->not->toBeEmpty();
    foreach ($response->json('activity_log') as $entry) {
        expect($entry['user']['username'])->toBe('searchableadmin');
    }
});

it('filters the activity log by type', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;
    $role = Role::create(['name' => 'Support Staff', 'slug' => 'support-staff']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/admin/roles/{$role->id}");

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log?filter[type]=role');

    expect($response->json('activity_log'))->not->toBeEmpty();
    foreach ($response->json('activity_log') as $entry) {
        expect($entry['type'])->toBe('role');
    }
});

it('returns the known distinct types and actions for filter dropdowns', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log/filters');

    $response->assertOk()
        ->assertJsonPath('types', ['cronjob', 'database', 'disk_cleaner', 'firewall', 'log', 'permission', 'role', 'service', 'setting', 'system_user', 'user'])
        ->assertJsonCount(46, 'actions.all'); // all distinct verbs (deduped across types)
    // `all` = every verb; per-type keys are scoped to that type's verbs.
    expect($response->json('actions.all'))->toContain('registered', 'created', 'impersonation_started', 'ssh_key_added', 'sudo_enabled', 'shell_changed', 'ssh_enabled', 'downloaded', 'cleaned', 'schedule_updated', 'profile_updated', 'user_created', 'connection_updated');
    expect($response->json('actions.database'))->toContain('created', 'deleted', 'user_created', 'user_deleted', 'password_reset', 'imported', 'connection_updated');
    expect($response->json('actions.system_user'))->toContain('created', 'ssh_key_added', 'password_set', 'sudo_enabled', 'shell_changed', 'ssh_enabled', 'ssh_disabled')->not->toContain('registered');
    expect($response->json('actions.role'))->toEqual(['created', 'deleted', 'updated']);
});

it('denies a regular user from viewing activity-log filter options', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/activity-log/filters')
        ->assertForbidden();
});
