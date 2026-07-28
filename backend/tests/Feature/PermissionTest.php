<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

it('creates the Administrator system role with every permission, idempotently', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class); // re-run: must not duplicate

    $admin = Role::where('slug', 'administrator')->get();
    expect($admin)->toHaveCount(1);
    expect($admin->first()->is_system)->toBeTrue();
    // holds all 12 permissions, view+manage
    expect($admin->first()->permissions()->count())->toBe(12);
    foreach ($admin->first()->permissions as $permission) {
        expect((bool) $permission->pivot->view)->toBeTrue();
        expect((bool) $permission->pivot->manage)->toBeTrue();
    }
});

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
    grantPermission($user, 'dashboard', view: true, manage: false);

    $token = $user->createToken('test')->plainTextToken;
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions');

    $response->assertOk()->assertJsonCount(1, 'permissions');
    $response->assertJsonPath('permissions.0.name', 'dashboard');
    $response->assertJsonPath('permissions.0.permissions.view', true);
    $response->assertJsonPath('permissions.0.permissions.manage', false);
});

it('automatically grants view when a role is given manage', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    // manage-implies-view is enforced by PermissionResolver on the role path.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/roles', [
            'name' => 'Firewall Manager',
            'permissions' => [
                ['level' => 'server', 'name' => 'firewall', 'view' => false, 'manage' => true],
            ],
        ]);

    $response->assertCreated();
    $firewall = collect($response->json('role.permissions'))->firstWhere('name', 'firewall');
    expect($firewall['permissions']['view'])->toBeTrue();
    expect($firewall['permissions']['manage'])->toBeTrue();
});

it('leaves parent_id null for all seeded items for now', function () {
    $this->seed(PermissionSeeder::class);

    expect(Permission::whereNotNull('parent_id')->count())->toBe(0);
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

it('localizes the nav title from Accept-Language (nav endpoint)', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    // English (default)
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions?level=server')
        ->assertOk()
        ->assertJsonFragment(['name' => 'database', 'title' => 'Database']);

    // Spanish via Accept-Language
    $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept-Language' => 'es'])
        ->getJson('/api/permissions?level=server')
        ->assertOk()
        ->assertJsonFragment(['name' => 'database', 'title' => 'Base de datos'])
        ->assertJsonFragment(['name' => 'disk_cleaner', 'title' => 'Limpiador de disco']);
});

it('localizes the admin catalog title and falls back to the DB title when no nav key exists', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    // A permission with no matching nav.* key falls back to its stored title.
    Permission::forceCreate(['level' => 'server', 'sub_level' => 'server', 'name' => 'widget', 'title' => 'Widget', 'icon' => 'x', 'url' => '/widget', 'order' => 99]);

    $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept-Language' => 'ja'])
        ->getJson('/api/admin/permissions')
        ->assertOk()
        ->assertJsonFragment(['name' => 'database', 'title' => 'データベース']) // translated
        ->assertJsonFragment(['name' => 'widget', 'title' => 'Widget']);        // fallback
});
