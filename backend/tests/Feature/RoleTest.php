<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdministratorRole;
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

it('lets an admin delete a role and detaches it from users', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $role = Role::create(['name' => 'Support Staff', 'slug' => 'support-staff']);
    $target->roles()->attach($role);
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/admin/roles/{$role->id}")
        ->assertNoContent();

    expect(Role::find($role->id))->toBeNull();
    expect($target->fresh()->roles()->where('roles.id', $role->id)->exists())->toBeFalse();
});

it('lets an admin assign roles to a user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $roleA = Role::create(['name' => 'Support Staff', 'slug' => 'support-staff']);
    $roleB = Role::create(['name' => 'Ops', 'slug' => 'ops']);
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/roles", ['role_ids' => [$roleA->id, $roleB->id]])
        ->assertNoContent();

    expect($target->fresh()->roles()->pluck('roles.id')->sort()->values()->all())
        ->toBe([$roleA->id, $roleB->id]);
});

it('rejects assigning zero roles (every user keeps at least one)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/admin/users/{$target->id}/roles", ['role_ids' => []])
        ->assertUnprocessable();
});

it('grants permission via any assigned role, deduped and OR-merged across roles', function () {
    $this->seed(PermissionSeeder::class);
    $target = User::factory()->create();
    $dashboard = Permission::firstWhere('name', 'dashboard');

    $viewOnly = Role::create(['name' => 'Viewer', 'slug' => 'viewer']);
    $viewOnly->permissions()->attach($dashboard->id, ['view' => true, 'manage' => false]);
    $manager = Role::create(['name' => 'Manager', 'slug' => 'manager']);
    $manager->permissions()->attach($dashboard->id, ['view' => true, 'manage' => true]);

    // Two roles grant the same permission — the stronger (manage) wins, deduped.
    $target->roles()->sync([$viewOnly->id, $manager->id]);

    expect($target->fresh()->canView('dashboard'))->toBeTrue();
    expect($target->fresh()->canManage('dashboard'))->toBeTrue();
});

it('protects the Administrator system role from deletion', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;
    $adminRole = app(AdministratorRole::class)->ensure();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/admin/roles/{$adminRole->id}")
        ->assertUnprocessable();

    expect(Role::find($adminRole->id))->not->toBeNull();
});
