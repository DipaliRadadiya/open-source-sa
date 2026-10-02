<?php

use App\Models\Application;
use App\Models\Role;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Central\CentralUser;
use App\Services\Server\CentralTokenManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The central panel calling this server with its token.
 *
 * Before this, the token could be minted, stored, masked, revoked and
 * validated — and reached nothing. `CentralSystemGuard` was registered and
 * applied to zero routes, so every call central made got a 401 from Sanctum,
 * which does not know about a raw token kept in `settings`.
 *
 * Two halves matter here and they pull in opposite directions: the token has
 * to reach the whole API, and *nothing else* may become easier to reach
 * because of it.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    // A real admin has to exist: without one the panel is in first-run state
    // and answers differently.
    User::factory()->admin()->create();

    $this->token = app(CentralTokenManager::class)->enable()['central_token'];

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);
});

/**
 * @return array<string, string>
 */
function central(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

it('reaches the API with the server token', function () {
    // Not one endpoint: the point of the decision was that central uses the
    // whole API, so a sample across unrelated feature areas is what proves it
    // rather than a single lucky route.
    foreach ([
        '/api/applications',
        '/api/system-users',
        '/api/cronjobs',
        '/api/permissions',
    ] as $endpoint) {
        $this->getJson($endpoint, central($this->token))->assertOk();
    }
});

it('acts as the machine account, not as the admin who connected', function () {
    $this->getJson('/api/applications', central($this->token))->assertOk();

    $actor = User::query()->where('is_system', true)->first();

    expect($actor)->not->toBeNull()
        ->and($actor->username)->toBe(CentralUser::USERNAME)
        // The account carries the Administrator role rather than a bypass, so
        // every `permission:` check downstream resolves the ordinary way.
        ->and($actor->roles()->where('slug', 'administrator')->exists())->toBeTrue()
        // A person's account is never turned into the integration's.
        ->and($actor->is_admin)->toBeTrue();
});

it('reuses one machine account across many calls', function () {
    // A new row per request would make the activity log unreadable and the
    // user list grow forever.
    for ($i = 0; $i < 3; $i++) {
        $this->getJson('/api/applications', central($this->token))->assertOk();
    }

    expect(User::query()->where('is_system', true)->count())->toBe(1);
});

it('stops the moment the connection is revoked', function () {
    $this->getJson('/api/applications', central($this->token))->assertOk();

    app(CentralTokenManager::class)->disable();

    // The test client reuses one container across requests, so a guard that
    // resolved a user on the previous call still holds it. php-fpm boots a
    // fresh container per request; this is the equivalent.
    Auth::forgetGuards();

    // The stored value is what every request is compared against, so revoking
    // takes effect on the next call rather than whenever something expires.
    $this->getJson('/api/applications', central($this->token))->assertUnauthorized();
});

it('issues a new token that works and retires the old one', function () {
    $replacement = app(CentralTokenManager::class)->enable()['central_token'];

    $this->getJson('/api/applications', central($replacement))->assertOk();

    Auth::forgetGuards();

    $this->getJson('/api/applications', central($this->token))->assertUnauthorized();
});

/*
 * The half that matters more: this middleware now runs on every API request
 * in the panel, so the branch that does nothing has to genuinely do nothing.
 */
describe('nothing else became easier to reach', function () {
    it('refuses a wrong token exactly as before', function () {
        $this->getJson('/api/applications', central('sv_central_notrealtoken000000000'))
            ->assertUnauthorized();
    });

    it('refuses a request with no token at all', function () {
        $this->getJson('/api/applications')->assertUnauthorized();
    });

    it('leaves an ordinary user exactly where they were', function () {
        // A viewer with no grants: still refused, and refused by the
        // permission layer rather than waved through by the new middleware.
        $viewer = User::factory()->create();

        $this->getJson('/api/applications', central($viewer->createToken('t')->plainTextToken))
            ->assertForbidden();
    });

    it('does not sign anyone in when central has never been connected', function () {
        DB::table('settings')->where('id', 1)->update(['central_token' => null]);

        // The empty-token case is the one worth pinning: a comparison against
        // a null stored value must never succeed for an empty presented one.
        $this->getJson('/api/applications', central(''))->assertUnauthorized();
        $this->getJson('/api/applications', ['Authorization' => 'Bearer '])->assertUnauthorized();
        $this->getJson('/api/applications')->assertUnauthorized();
    });

    it('does not treat a sanctum token as a central one', function () {
        $admin = User::factory()->admin()->create();

        $this->getJson('/api/applications', central($admin->createToken('t')->plainTextToken))
            ->assertOk();

        // Authenticated as themselves, and no machine account conjured up.
        expect(User::query()->where('is_system', true)->exists())->toBeFalse();
    });
});

/*
 * Bug #50: central signs in as an administrator, so with one leaked key it
 * could reset an administrator's password, create a new administrator or
 * hand out roles. Who runs this panel is decided on it, not by central.
 */
it('cannot change the panel\'s users or roles', function () {
    $admin = User::where('is_admin', true)->first();
    $role = Role::create(['name' => 'Editors', 'slug' => 'editors']);
    // Central's own machine account is created on its first request.
    $this->withHeaders(central($this->token))->getJson('/api/admin/users')->assertOk();
    $before = [User::count(), $admin->fresh()->password, Role::count()];

    foreach ([
        ['POST', '/api/admin/users', ['username' => 'intruder', 'email' => 'i@example.test', 'password' => 'Str0ng-Passw0rd!', 'role_ids' => [$role->id]]],
        ['PUT', "/api/admin/users/{$admin->id}", ['email' => 'x@example.test']],
        ['PUT', "/api/admin/users/{$admin->id}/reset-password", ['password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!']],
        ['PUT', "/api/admin/users/{$admin->id}/roles", ['role_ids' => [$role->id]]],
        ['POST', "/api/admin/users/{$admin->id}/impersonate", []],
        ['DELETE', "/api/admin/users/{$admin->id}", []],
        ['POST', '/api/admin/roles', ['name' => 'Owners']],
        ['PUT', "/api/admin/roles/{$role->id}", ['name' => 'Renamed']],
        ['DELETE', "/api/admin/roles/{$role->id}", []],
    ] as [$method, $uri, $body]) {
        $this->withHeaders(central($this->token))->json($method, $uri, $body)
            ->assertForbidden()
            ->assertJsonPath('message', __('central.errors.not_for_central'));
    }

    expect([User::count(), $admin->fresh()->password, Role::count()])->toBe($before);
});

it('can still read the panel\'s users and roles', function () {
    $this->withHeaders(central($this->token))->getJson('/api/admin/users')->assertOk();
    $this->withHeaders(central($this->token))->getJson('/api/admin/roles')->assertOk();
});

it('leaves an administrator free to manage users', function () {
    $admin = User::where('is_admin', true)->first();

    $this->withHeaders(['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken])
        ->postJson('/api/admin/roles', ['name' => 'Owners'])->assertCreated();
});
