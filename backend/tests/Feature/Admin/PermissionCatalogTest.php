<?php

use App\Models\ActivityLog;
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
        ->assertJsonStructure(['permissions' => [['level', 'sub_level', 'sub_level_title', 'name', 'title', 'icon', 'url']]]);

    $names = collect($response->json('permissions'))->pluck('name');
    expect($names)->toContain('system_user', 'cronjob', 'git', 'storage');
    // catalog is metadata only — no per-permission grant state
    expect($response->json('permissions.0'))->not->toHaveKey('permissions');
});

it('re-syncs the permission catalog for an admin', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    // wipe the catalog, then sync should restore it (idempotent, from code)
    Permission::query()->delete();
    expect(Permission::count())->toBe(0);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/permissions/sync');

    $response->assertOk()
        ->assertJsonPath('synced', 33)
        ->assertJsonCount(33, 'permissions');
    expect(Permission::count())->toBe(33);

    // audit entry recorded
    $log = ActivityLog::where('type', 'permission')->where('action', 'synced')->latest('id')->first();
    expect($log->properties['count'])->toBe(33);
});

it('denies a non-admin from syncing permissions', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/permissions/sync')
        ->assertForbidden();
});

it('denies a non-admin from viewing the permission catalog', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/permissions')
        ->assertForbidden();
});
