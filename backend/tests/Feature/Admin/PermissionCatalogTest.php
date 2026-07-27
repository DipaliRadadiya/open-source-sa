<?php

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

it('returns the full permission catalog to an admin', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/permissions');

    $response->assertOk()
        ->assertJsonCount(Permission::count(), 'permissions')
        ->assertJsonStructure(['permissions' => [['level', 'sub_level', 'name', 'title', 'icon', 'url']]]);

    $names = collect($response->json('permissions'))->pluck('name');
    expect($names)->toContain('system_user', 'cronjob');
    // catalog is metadata only — no per-permission grant state
    expect($response->json('permissions.0'))->not->toHaveKey('permissions');
});

it('denies a non-admin from viewing the permission catalog', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/permissions')
        ->assertForbidden();
});
