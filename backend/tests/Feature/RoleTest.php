<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

it('lets an admin create a role with permissions', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/roles', [
            'name' => 'Support Staff',
            'description' => 'Read-only access to logs and dashboard',
            'permissions' => [
                ['level' => 'server', 'name' => 'dashboard', 'view' => true, 'manage' => false],
                ['level' => 'server', 'name' => 'logs', 'view' => true, 'manage' => false],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('role.name', 'Support Staff')
        ->assertJsonPath('role.slug', 'support-staff')
        ->assertJsonCount(2, 'role.permissions');

    expect(Role::where('name', 'Support Staff')->exists())->toBeTrue();
});

it('treats role names as duplicates regardless of casing', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;
    Role::create(['name' => 'Support Staff', 'slug' => 'support-staff']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/roles', ['name' => 'support staff']);

    $response->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('denies a regular user from creating a role', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/roles', ['name' => 'X'])
        ->assertForbidden();
});

it('lets an admin update a role and its permissions', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;
    $role = Role::create(['name' => 'Support Staff', 'slug' => 'support-staff']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/roles/{$role->id}", [
            'name' => 'Support Staff (Updated)',
            'permissions' => [
                ['level' => 'server', 'name' => 'firewall', 'view' => true, 'manage' => true],
            ],
        ]);

    $response->assertOk()->assertJsonPath('role.name', 'Support Staff (Updated)');
    expect($role->fresh()->permissions()->count())->toBe(1);
});

it('lets an admin delete a role and unassigns it from users', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $role = Role::create(['name' => 'Support Staff', 'slug' => 'support-staff']);
    $target->update(['role_id' => $role->id]);
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/admin/roles/{$role->id}")
        ->assertNoContent();

    expect(Role::find($role->id))->toBeNull();
    expect($target->fresh()->role_id)->toBeNull();
});

it('lets an admin assign a role to a user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $role = Role::create(['name' => 'Support Staff', 'slug' => 'support-staff']);
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/role", ['role_id' => $role->id])
        ->assertNoContent();

    expect($target->fresh()->role_id)->toBe($role->id);
});

it('grants a user permission access via their assigned role, not just direct grants', function () {
    $this->seed(PermissionSeeder::class);
    $target = User::factory()->create();
    $role = Role::create(['name' => 'Support Staff', 'slug' => 'support-staff']);
    $dashboard = Permission::firstWhere('name', 'dashboard');
    $role->permissions()->attach($dashboard->id, ['view' => true, 'manage' => false]);
    $target->update(['role_id' => $role->id]);

    expect($target->fresh()->canView('dashboard'))->toBeTrue();
    expect($target->fresh()->canManage('dashboard'))->toBeFalse();
});

it('unassigns a role when role_id is sent as null', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::create(['name' => 'Support Staff', 'slug' => 'support-staff']);
    $target = User::factory()->create(['role_id' => $role->id]);
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/role", ['role_id' => null])
        ->assertNoContent();

    expect($target->fresh()->role_id)->toBeNull();
});
