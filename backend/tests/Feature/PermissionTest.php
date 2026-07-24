<?php

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

it('seeds the 12 server-level permission items in order', function () {
    $this->seed(PermissionSeeder::class);

    expect(Permission::count())->toBe(12);
    expect(Permission::orderBy('order')->pluck('name')->first())->toBe('dashboard');
    expect(Permission::orderBy('order')->pluck('name')->last())->toBe('activity_log');
    expect(Permission::pluck('level')->unique()->all())->toBe(['server']);
    expect(Permission::pluck('sub_level')->unique()->all())->toBe(['server']);
});

it('shows an admin every permission with full view+manage access', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions');

    $response->assertOk()->assertJsonCount(12, 'permissions');
    foreach ($response->json('permissions') as $permission) {
        expect($permission['permissions']['view'])->toBeTrue();
        expect($permission['permissions']['manage'])->toBeTrue();
    }
});

it('shows a regular user only the permissions they were granted', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    $dashboard = Permission::firstWhere('name', 'dashboard');
    $user->permissions()->attach($dashboard->id, ['view' => true, 'manage' => false]);

    $token = $user->createToken('test')->plainTextToken;
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions');

    $response->assertOk()->assertJsonCount(1, 'permissions');
    $response->assertJsonPath('permissions.0.name', 'dashboard');
    $response->assertJsonPath('permissions.0.permissions.view', true);
    $response->assertJsonPath('permissions.0.permissions.manage', false);
});

it('lets an admin assign permissions to a user', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/permissions", [
            'permissions' => [
                ['level' => 'server', 'name' => 'dashboard', 'view' => true, 'manage' => true],
                ['level' => 'server', 'name' => 'firewall', 'view' => true, 'manage' => false],
            ],
        ]);

    $response->assertNoContent();
    expect($target->permissions()->count())->toBe(2);
});

it('does not assign a permission when the level does not match the name', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/permissions", [
            'permissions' => [
                ['level' => 'application', 'name' => 'dashboard', 'view' => true, 'manage' => true],
            ],
        ])
        ->assertNoContent();

    expect($target->permissions()->count())->toBe(0);
});

it('automatically grants view when manage is granted', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/permissions", [
            'permissions' => [
                ['level' => 'server', 'name' => 'firewall', 'view' => false, 'manage' => true],
            ],
        ])
        ->assertNoContent();

    $grant = $target->permissions()->first();
    expect((bool) $grant->pivot->view)->toBeTrue();
    expect((bool) $grant->pivot->manage)->toBeTrue();
});

it('leaves parent_id null for all seeded items for now', function () {
    $this->seed(PermissionSeeder::class);

    expect(Permission::whereNotNull('parent_id')->count())->toBe(0);
});

it('denies a regular user from assigning permissions', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    $target = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/permissions", [
            'permissions' => [['level' => 'server', 'name' => 'dashboard', 'view' => true, 'manage' => true]],
        ])
        ->assertForbidden();
});

it('filters the check endpoint by level', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions/check?level=server');

    $response->assertOk()->assertJsonCount(12, 'permissions');
});

it('requires a level on the check endpoint', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions/check')
        ->assertUnprocessable();
});
