<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('allows an admin to list users', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(2)->create();
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/users');

    $response->assertOk()->assertJsonCount(3, 'users');
});

it('denies a regular user from listing users', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/users')
        ->assertForbidden();
});

it('allows an admin to create a user', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/users', [
            'name' => 'New User',
            'username' => 'newuser',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'user',
        ]);

    $response->assertCreated()->assertJsonPath('user.username', 'newuser');
    expect(User::where('username', 'newuser')->exists())->toBeTrue();
});

it('denies a regular user from creating a user', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/users', [
            'name' => 'New User',
            'username' => 'newuser',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'user',
        ])
        ->assertForbidden();

    expect(User::where('username', 'newuser')->exists())->toBeFalse();
});

it('allows an admin to reset another user\'s password', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['password' => bcrypt('OldPassword123')]);
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/reset-password", [
            'password' => 'ResetPassword123',
            'password_confirmation' => 'ResetPassword123',
        ]);

    $response->assertNoContent();
    expect(Hash::check('ResetPassword123', $target->fresh()->password))->toBeTrue();
});

it('denies a regular user from resetting another user\'s password', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/reset-password", [
            'password' => 'ResetPassword123',
            'password_confirmation' => 'ResetPassword123',
        ])
        ->assertForbidden();
});

it('allows an admin to update a user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Old Name', 'username' => 'oldname']);
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}", [
            'name' => 'New Name',
            'username' => 'newname',
            'role' => 'admin',
        ]);

    $response->assertOk()->assertJsonPath('user.username', 'newname');
    expect($target->fresh())
        ->name->toBe('New Name')
        ->username->toBe('newname')
        ->role->toBe(\App\Enums\UserRole::Admin);
});

it('denies a regular user from updating a user', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}", [
            'name' => 'New Name',
            'username' => 'newname',
            'role' => 'user',
        ])
        ->assertForbidden();
});

it('allows an admin to delete another user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/admin/users/{$target->id}")
        ->assertNoContent();

    expect(User::find($target->id))->toBeNull();
});

it('prevents an admin from deleting their own account', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/admin/users/{$admin->id}")
        ->assertUnprocessable();

    expect(User::find($admin->id))->not->toBeNull();
});

it('denies a regular user from deleting a user', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/admin/users/{$target->id}")
        ->assertForbidden();
});

it('lets an admin search users by name or username', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['name' => 'Jane Cooper', 'username' => 'janecooper']);
    User::factory()->create(['name' => 'John Smith', 'username' => 'johnsmith']);
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/users?search=jane');

    $response->assertOk()->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.username', 'janecooper');
});

it('lets an admin filter users by role', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(2)->create(['role' => \App\Enums\UserRole::User]);
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/users?filter[role]=admin');

    $response->assertOk()->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.username', $admin->username);
});

it('rejects an invalid role filter on the users list', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/users?filter[role]=superadmin')
        ->assertUnprocessable();
});

it('rejects an out-of-range per_page on the users list', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/users?per_page=999999')
        ->assertUnprocessable();
});

it('revokes a deleted user\'s tokens', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $target->createToken('target-token');
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/admin/users/{$target->id}")
        ->assertNoContent();

    expect(\Laravel\Sanctum\PersonalAccessToken::where('tokenable_id', $target->id)->count())->toBe(0);
});
