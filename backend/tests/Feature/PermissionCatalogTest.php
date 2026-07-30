<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdministratorRole;
use App\Services\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Testing\TestResponse;

function register(array $overrides = []): TestResponse
{
    return test()->postJson('/api/auth/register', array_merge([
        'name' => 'First Admin',
        'username' => 'admin',
        'password' => 'Sup3rSecret!pass',
        'password_confirmation' => 'Sup3rSecret!pass',
    ], $overrides));
}

it('gives the first admin every permission even when the seeder never ran', function () {
    // The fresh-install path: clone, migrate, boot, register. Before this
    // fix the admin got an Administrator role holding nothing — an empty
    // sidebar and a 403 on every feature, while the admin area still worked,
    // which reads as a successful install.
    expect(Permission::count())->toBe(0);

    register()->assertCreated();

    $user = User::first();
    $catalog = Permission::count();

    expect($catalog)->toBe(count(app(PermissionCatalog::class)->items()))
        ->and($user->roles()->first()->permissions()->count())->toBe($catalog);

    foreach (Permission::pluck('name') as $name) {
        expect($user->canView($name))->toBeTrue("cannot view {$name}")
            ->and($user->canManage($name))->toBeTrue("cannot manage {$name}");
    }
});

it('gives that admin a usable panel, not just rows in a table', function () {
    register()->assertCreated();

    $token = User::first()->createToken('t')->plainTextToken;
    $auth = fn (string $uri) => test()->withHeader('Authorization', "Bearer {$token}")->getJson($uri);

    // The three things that were broken: an empty sidebar, a 403 on every
    // feature, and an admin area that worked anyway.
    expect($auth('/api/permissions')->assertOk()->json('permissions'))->not->toBeEmpty();
    $auth('/api/settings')->assertOk();
    $auth('/api/admin/users')->assertOk();
});

it('does not disturb anything when the catalog is already seeded', function () {
    $this->seed(PermissionSeeder::class);

    $before = Permission::query()->orderBy('id')->get(['id', 'name', 'title', 'order'])->toArray();

    register()->assertCreated();

    // Idempotent: same rows, same ids — not a fresh set with the old ones
    // orphaned off whatever roles referenced them.
    expect(Permission::query()->orderBy('id')->get(['id', 'name', 'title', 'order'])->toArray())
        ->toBe($before);
});

it('leaves no user behind when registration fails partway', function () {
    // Registration closes the moment a user row exists, so a half-written
    // registration would mean a broken admin behind a permanently closed
    // door — no retry, no fix from the UI.
    Role::creating(fn () => throw new RuntimeException('boom'));

    register()->assertStatus(500);

    // Everything rolled back together, or the door locks on a broken admin.
    expect(User::count())->toBe(0)
        ->and(Permission::count())->toBe(0);

    // And registration is still open, so it can simply be retried.
    Role::flushEventListeners();
    register()->assertCreated();
    expect(User::first()->canManage('setting'))->toBeTrue();
});

it('produces the same catalog through the seeder and the service', function () {
    $this->seed(PermissionSeeder::class);
    $viaSeeder = Permission::query()->orderBy('order')->pluck('name')->all();

    Permission::query()->delete();
    app(PermissionCatalog::class)->sync();

    // Two callers, one definition — the point of extracting it.
    expect(Permission::query()->orderBy('order')->pluck('name')->all())->toBe($viaSeeder)
        ->and($viaSeeder)->toHaveCount(count(app(PermissionCatalog::class)->items()));
});

it('keeps the admin sync endpoint working through the service', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    Permission::query()->where('name', 'firewall')->delete();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/permissions/sync')
        ->assertOk();

    // Mass assignment on a code-managed catalog: the service unguards for
    // itself now, so no caller has to remember to.
    expect($response->json('synced'))->toBe(count(app(PermissionCatalog::class)->items()))
        ->and(Permission::query()->where('name', 'firewall')->exists())->toBeTrue();
});

it('adds a new permission to the Administrator role without touching other roles', function () {
    $this->seed(PermissionSeeder::class);

    $limited = Role::create(['name' => 'Limited', 'slug' => 'limited']);
    $limited->permissions()->sync([Permission::where('name', 'firewall')->value('id') => ['view' => true, 'manage' => false]]);

    Permission::forceCreate(['name' => 'brand_new', 'level' => 'server', 'sub_level' => 'server', 'title' => 'New', 'icon' => 'x', 'url' => '/new', 'order' => 99]);
    app(AdministratorRole::class)->ensure();

    expect(Role::where('slug', 'administrator')->first()->permissions()->count())->toBe(Permission::count())
        // A resync must not quietly widen a role somebody deliberately narrowed.
        ->and($limited->fresh()->permissions()->count())->toBe(1);
});
