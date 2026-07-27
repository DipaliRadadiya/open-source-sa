<?php

use App\Models\ActivityLog;
use App\Models\User;

it('lets a user update their own name and username', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'username' => 'oldname']);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/auth/profile', ['name' => 'New Name', 'username' => 'newname'])
        ->assertOk()
        ->assertJsonPath('user.name', 'New Name')
        ->assertJsonPath('user.username', 'newname');

    expect($user->fresh()->only('name', 'username'))->toBe(['name' => 'New Name', 'username' => 'newname']);
    expect(ActivityLog::where('type', 'user')->where('action', 'profile_updated')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('allows keeping the same username (ignores self in the unique check)', function () {
    $user = User::factory()->create(['username' => 'sameuser']);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/auth/profile', ['name' => 'Renamed', 'username' => 'sameuser'])
        ->assertOk();
});

it('rejects a username already taken by another user', function () {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create(['username' => 'mine']);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/auth/profile', ['name' => 'X', 'username' => 'taken'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('requires name and username', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/auth/profile', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'username']);
});

it('requires authentication', function () {
    $this->putJson('/api/auth/profile', ['name' => 'X', 'username' => 'y'])->assertUnauthorized();
});
