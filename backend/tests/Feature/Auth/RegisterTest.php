<?php

use App\Models\User;
use App\Services\AdministratorRole;

it('registers the first user as admin with the Administrator role', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'First User',
        'username' => 'firstadmin',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.username', 'firstadmin')
        ->assertJsonPath('user.is_admin', true)
        ->assertJsonStructure(['user' => ['id', 'name', 'username', 'is_admin', 'roles'], 'token']);

    $user = User::first();
    expect($user->is_admin)->toBeTrue();
    // gets the protected Administrator role (>= 1 role invariant)
    expect($user->roles()->where('slug', AdministratorRole::SLUG)->exists())->toBeTrue();
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
