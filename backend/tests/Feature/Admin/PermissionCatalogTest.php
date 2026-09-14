<?php

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use App\Services\PermissionCatalog;
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

    // Counted from the catalog rather than written here as a number. The
    // assertion is "sync restores every permission the code defines", and a
    // literal made that a lie the moment somebody added one: this test went
    // red on a commit that correctly added a permission and updated the other
    // permission test, because the count lived in two places.
    $defined = count(app(PermissionCatalog::class)->items());

    $response->assertOk()
        ->assertJsonPath('synced', $defined)
        ->assertJsonCount($defined, 'permissions');
    expect(Permission::count())->toBe($defined);

    // audit entry recorded
    $log = ActivityLog::where('type', 'permission')->where('action', 'synced')->latest('id')->first();
    expect($log->properties['count'])->toBe($defined);
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
