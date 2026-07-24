<?php

use App\Enums\UserRole;
use App\Models\User;

it('registers the first user as admin when no users exist', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'First User',
        'username' => 'firstadmin',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.username', 'firstadmin')
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonStructure(['user' => ['id', 'name', 'username', 'role'], 'token']);

    expect(User::first()->role)->toBe(UserRole::Admin);
});

it('rejects registration once a user already exists', function () {
    User::factory()->admin()->create();

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Second User',
        'username' => 'seconduser',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $response->assertForbidden();
    expect(User::count())->toBe(1);
});

it('validates registration input', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => '',
        'username' => '',
        'password' => 'short',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'username', 'password']);
});
