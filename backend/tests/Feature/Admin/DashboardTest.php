<?php

use App\Models\User;

it('lets an admin view dashboard stats', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(2)->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/users', userPayload(['username' => 'newuser']));

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/dashboard');

    $response->assertOk()
        ->assertJsonPath('dashboard.users.total', 4)
        ->assertJsonPath('dashboard.users.admins', 1)
        ->assertJsonPath('dashboard.users.non_admins', 3);

    expect($response->json('dashboard.roles.total'))->toBeGreaterThan(0);
    expect($response->json('dashboard.activity.today'))->toBeGreaterThan(0);
});

it('denies a regular user from viewing the dashboard', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/dashboard')
        ->assertForbidden();
});
