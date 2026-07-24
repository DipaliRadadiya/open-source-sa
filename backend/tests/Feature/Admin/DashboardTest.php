<?php

use App\Models\User;

it('lets an admin view dashboard stats', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(2)->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/users', [
        'name' => 'New User', 'username' => 'newuser',
        'password' => 'Password123', 'password_confirmation' => 'Password123', 'role' => 'user',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/dashboard');

    $response->assertOk()
        ->assertJsonPath('dashboard.users.total', 4)
        ->assertJsonPath('dashboard.users.admin', 1)
        ->assertJsonPath('dashboard.users.user', 3)
        ->assertJsonPath('dashboard.roles.total', 0);

    expect($response->json('dashboard.activity.today'))->toBeGreaterThan(0);
});

it('denies a regular user from viewing the dashboard', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/dashboard')
        ->assertForbidden();
});
