<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionCatalog;
use Database\Seeders\PermissionSeeder;

it('creates the Administrator system role with every permission, idempotently', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class); // re-run: must not duplicate

    $admin = Role::where('slug', 'administrator')->get();
    expect($admin)->toHaveCount(1);
    expect($admin->first()->is_system)->toBeTrue();
    // holds every permission at both levels, view+manage
    expect($admin->first()->permissions()->count())->toBe(38);
    foreach ($admin->first()->permissions as $permission) {
        expect((bool) $permission->pivot->view)->toBeTrue();
        expect((bool) $permission->pivot->manage)->toBeTrue();
    }
});

it('seeds the server and application permission items in order', function () {
    $this->seed(PermissionSeeder::class);

    expect(Permission::count())->toBe(38);
    expect(Permission::where('level', 'server')->count())->toBe(20);
    expect(Permission::where('level', 'application')->count())->toBe(18);

    $server = Permission::where('level', 'server')->orderBy('order');
    expect($server->pluck('name')->first())->toBe('dashboard');
    // `registry` is appended last rather than slotted in, so nothing already there moved.
    expect($server->pluck('name')->last())->toBe('registry');

    $app = Permission::where('level', 'application')->orderBy('order');
    expect($app->pluck('name')->first())->toBe('app_dashboard');
    // Last because it is not a screen: `app_magic_login` has a null url and is filtered out of the
    // sidebar, so its order only decides where it sits in the role editor's list.
    expect($app->pluck('name')->last())->toBe('app_magic_login');

    // Every application permission carries the `app_` prefix: hasAbility() resolves by name and
    // ignores level, so a collision with a server-level name would silently grant one for the other.
    expect(Permission::where('level', 'application')->pluck('name')
        ->every(fn (string $name) => str_starts_with($name, 'app_')))->toBeTrue();
});

it('groups the git, storage and registry permissions under the integration sub-level', function () {
    $this->seed(PermissionSeeder::class);

    $integrations = Permission::where('sub_level', 'integration')->orderBy('order')->get();

    // Registry credentials belong here like git and storage: an externally-held credential the
    // features consume.
    expect($integrations->pluck('name')->all())->toBe(['git', 'storage', 'registry']);
    expect($integrations->pluck('level')->unique()->all())->toBe(['server']);
    expect($integrations->pluck('url')->all())->toBe([
        '/integrations/git', '/integrations/storage', '/integrations/registries',
    ]);
    // the existing items are untouched — no sidebar churn
    expect(Permission::where('sub_level', 'server')->count())->toBe(17);
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

    // Derived, not written down: a literal goes stale the moment a permission is added.
    //
    // Two are withheld: `docker` and `registry` are gated on the server hosting containers, and a
    // test with no capability row falls back to `ServerCapabilities::DEFAULT_PROFILES` —
    // php/node/static, no docker — the same answer the Docker endpoints give on an unknown box.
    $withheldOffAContainerStack = ['docker', 'registry'];
    $expected = count(app(PermissionCatalog::class)->items()) - count($withheldOffAContainerStack);

    $response->assertOk()->assertJsonCount($expected, 'permissions');

    expect(collect($response->json('permissions'))->pluck('name'))
        ->not->toContain(...$withheldOffAContainerStack);
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

    // level=server spans both sub-levels — the grouping is a display concern.
    //
    // `docker` and `registry` are server-level but gated on hosting containers, and a test box has
    // no capability row, so neither is offered. A literal rather than catalog arithmetic: deriving
    // the server-level subset would mean reimplementing the filter inside the test that checks it.
    $response->assertOk()->assertJsonCount(18, 'permissions');

    // …and level=application returns the sidebar rendered *inside* an app.
    // Each level is its own sidebar; this filter is what separates them.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/permissions/check?level=application')
        ->assertOk()
        ->assertJsonCount(18, 'permissions')
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
        ->assertJsonFragment(['name' => 'database', 'title' => 'Databases']);

    // Spanish via Accept-Language
    $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept-Language' => 'es'])
        ->getJson('/api/permissions?level=server')
        ->assertOk()
        ->assertJsonFragment(['name' => 'database', 'title' => 'Bases de datos'])
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

    // A permission with no `nav.*` key falls back to its hardcoded English title: right for an
    // unknown permission, wrong to rely on for a shipped one, and nothing else would fail.
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

    // `firewall` opens and closes ports; `app_firewall` is the WAF rule set for one site. Sharing a
    // label would make someone believe enabling a WAF closed a port.
    foreach (config('app.available_locales', ['en']) as $locale) {
        $nav = trans('nav', [], $locale);

        expect($nav['app_firewall'])->not->toBe($nav['firewall'])
            ->and($nav['app_log'])->not->toBe($nav['logs']);
    }
});
