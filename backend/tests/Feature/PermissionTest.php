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
    // holds every permission at both levels, view+manage
    expect($admin->first()->permissions()->count())->toBe(33);
    foreach ($admin->first()->permissions as $permission) {
        expect((bool) $permission->pivot->view)->toBeTrue();
        expect((bool) $permission->pivot->manage)->toBeTrue();
    }
});

it('seeds the server and application permission items in order', function () {
    $this->seed(PermissionSeeder::class);

    expect(Permission::count())->toBe(33);
    expect(Permission::where('level', 'server')->count())->toBe(18);
    expect(Permission::where('level', 'application')->count())->toBe(15);

    $server = Permission::where('level', 'server')->orderBy('order');
    expect($server->pluck('name')->first())->toBe('dashboard');
    expect($server->pluck('name')->last())->toBe('storage');

    $app = Permission::where('level', 'application')->orderBy('order');
    expect($app->pluck('name')->first())->toBe('app_dashboard');
    expect($app->pluck('name')->last())->toBe('app_clone');

    // Every application permission carries the `app_` prefix. hasAbility()
    // resolves by name and ignores level, so a collision with a server-level
    // name would silently grant one through the other.
    expect(Permission::where('level', 'application')->pluck('name')
        ->every(fn (string $name) => str_starts_with($name, 'app_')))->toBeTrue();
});

it('groups the git and storage permissions under the integration sub-level', function () {
    $this->seed(PermissionSeeder::class);

    $integrations = Permission::where('sub_level', 'integration')->orderBy('order')->get();

    expect($integrations->pluck('name')->all())->toBe(['git', 'storage']);
    expect($integrations->pluck('level')->unique()->all())->toBe(['server']);
    expect($integrations->pluck('url')->all())->toBe(['/integrations/git', '/integrations/storage']);
    // the existing items are untouched — no sidebar churn
    expect(Permission::where('sub_level', 'server')->count())->toBe(16);
});

it('returns a localized sub-level header alongside each permission', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    grantPermission($user, 'git', view: true, manage: true);

    $token = $user->createToken('test')->plainTextToken;
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions');

    $response->assertOk()->assertJsonCount(1, 'permissions');
    $response->assertJsonPath('permissions.0.name', 'git');
    $response->assertJsonPath('permissions.0.sub_level', 'integration');
    $response->assertJsonPath('permissions.0.sub_level_title', 'Integrations');
});

it('translates the sub-level header for the requested locale', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    grantPermission($user, 'storage', view: true, manage: false);

    $token = $user->createToken('test')->plainTextToken;
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept-Language' => 'fr',
    ])->getJson('/api/permissions');

    $response->assertOk();
    $response->assertJsonPath('permissions.0.sub_level_title', 'Intégrations');
    $response->assertJsonPath('permissions.0.title', 'Stockage');
});

it('shows an admin every permission with full view+manage access', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions');

    $response->assertOk()->assertJsonCount(33, 'permissions');
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

    // level=server spans both sub-levels — the grouping is a display concern
    $response->assertOk()->assertJsonCount(18, 'permissions');

    // …and level=application returns the sidebar rendered *inside* an app.
    // Each level is its own sidebar; this filter is what separates them.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions/check?level=application')
        ->assertOk()
        ->assertJsonCount(15, 'permissions')
        ->assertJsonPath('permissions.0.name', 'app_dashboard');
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

it('has a nav label for every permission in every locale', function () {
    $this->seed(PermissionSeeder::class);

    // The application sidebar shipped with none of its labels translated: the
    // 15 `app_*` permissions had no `nav.*` keys at all, so every locale fell
    // through to the hardcoded English title and eight translated locales
    // rendered an English sidebar. Falling back is the right behaviour for an
    // unknown permission and the wrong behaviour to rely on for a shipped one,
    // and nothing failed when it happened — hence this.
    $names = Permission::pluck('name');
    $missing = [];

    foreach (config('app.available_locales', ['en']) as $locale) {
        $translations = trans('nav', [], $locale);

        foreach ($names as $name) {
            if (! is_array($translations) || ! array_key_exists($name, $translations)) {
                $missing[] = "{$locale}/nav.{$name}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('does not label a per-app screen the same as an unrelated server one', function () {
    $this->seed(PermissionSeeder::class);

    // `firewall` opens and closes ports; `app_firewall` is the 8G rule set
    // inspecting requests to one site. Sharing a label is how someone comes to
    // believe enabling a WAF closed a port.
    foreach (config('app.available_locales', ['en']) as $locale) {
        $nav = trans('nav', [], $locale);

        expect($nav['app_firewall'])->not->toBe($nav['firewall'])
            ->and($nav['app_log'])->not->toBe($nav['logs']);
    }
});
