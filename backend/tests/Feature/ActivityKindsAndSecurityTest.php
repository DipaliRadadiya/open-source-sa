<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityCatalog;
use App\Services\ActivityKinds;
use App\Services\ActivityScopes;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

/*
 * FS-C11, FS-C15, OLD-16/17/18: the server log's filters for non-admins, a
 * filter by kind of event, failed sign-ins recorded, last sign-in on each
 * user, and a security-events filter.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

function kindRow(string $type, string $action, ?User $user = null): ActivityLog
{
    return ActivityLog::create([
        'user_id' => ($user ?? test()->admin)->id,
        'type' => $type, 'action' => $action, 'properties' => [],
    ]);
}

describe('failed sign-ins and last sign-in (OLD-16/17)', function () {
    it('records a wrong password on the account it was tried against', function () {
        $user = User::factory()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);

        $this->postJson('/api/auth/login', ['username' => 'jdoe', 'password' => 'nope'])->assertUnprocessable();

        $row = ActivityLog::where('action', 'login_failed')->sole();
        expect($row->user_id)->toBe($user->id)
            ->and($row->properties['ip'])->toBe('127.0.0.1');

        // In their own history, where "someone tried my password" belongs.
        $this->actingAs($user)->getJson('/api/activity-log')
            ->assertJsonPath('activity_log.0.action', 'login_failed')
            ->assertJsonPath('activity_log.0.kind', 'failed')
            ->assertJsonPath('activity_log.0.is_security', true);
    });

    it('records an unknown name without storing what was typed', function () {
        $this->postJson('/api/auth/login', ['username' => 'MySecretPassw0rd', 'password' => 'x'])->assertUnprocessable();

        $row = ActivityLog::where('action', 'login_failed_unknown')->sole();
        expect($row->user_id)->toBeNull()
            ->and(json_encode($row->properties))->not->toContain('MySecretPassw0rd');

        $this->actingAs($this->admin)->getJson('/api/admin/activity-log?filter[action]=login_failed_unknown')
            ->assertOk()
            ->assertJsonPath('activity_log.0.is_system', false);
    });

    it('stamps the last sign-in and shows it on the user', function () {
        $user = User::factory()->create(['username' => 'jdoe', 'password' => bcrypt('Password123')]);
        expect($user->last_login_at)->toBeNull();

        $this->postJson('/api/auth/login', ['username' => 'jdoe', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('user.last_login_ip', '127.0.0.1');

        expect($user->fresh()->last_login_at)->not->toBeNull();

        $this->actingAs($this->admin)->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonFragment(['username' => 'jdoe', 'last_login_ip' => '127.0.0.1']);
    });
});

describe('kind of event (FS-C15)', function () {
    it('reads the kind from the action name', function (string $action, string $kind) {
        expect(app(ActivityKinds::class)->of($action))->toBe($kind);
    })->with([
        ['created', 'created'], ['installed', 'created'], ['uninstalled', 'removed'],
        ['connected', 'created'], ['disconnected', 'removed'], ['install_failed', 'failed'],
        ['fail2ban_enabled', 'changed'], ['updated', 'changed'], ['login_failed_unknown', 'failed'],
    ]);

    it('calls every catalog action ending in failed a failure', function () {
        $actions = app(ActivityCatalog::class)->keys()->map(fn ($k) => Str::after($k, '.'))->unique();

        foreach ($actions->filter(fn ($a) => str_ends_with($a, 'failed')) as $action) {
            expect(app(ActivityKinds::class)->of($action))->toBe('failed', $action);
        }
    });

    it('filters every log by kind', function () {
        kindRow('database', 'created');
        kindRow('database', 'deleted');
        kindRow('database', 'updated');
        kindRow('backup', 'failed');

        $this->actingAs($this->admin)->getJson('/api/admin/activity-log?filter[kind]=removed')
            ->assertOk()->assertJsonCount(1, 'activity_log')->assertJsonPath('activity_log.0.action', 'deleted');
        $this->actingAs($this->admin)->getJson('/api/admin/activity-log?filter[kind]=changed')
            ->assertOk()->assertJsonCount(1, 'activity_log')->assertJsonPath('activity_log.0.action', 'updated');
        $this->actingAs($this->admin)->getJson('/api/server/activity-log?filter[kind]=failed')
            ->assertOk()->assertJsonCount(1, 'activity_log')->assertJsonPath('activity_log.0.kind', 'failed');
        $this->actingAs($this->admin)->getJson('/api/activity-log?filter[kind]=created')
            ->assertOk()->assertJsonCount(1, 'activity_log');

        $this->actingAs($this->admin)->getJson('/api/admin/activity-log?filter[kind]=bogus')
            ->assertUnprocessable();
    });
});

describe('security events (OLD-18)', function () {
    it('names only real events', function () {
        $catalog = app(ActivityCatalog::class)->keys()->all();

        foreach ((array) config('activity.security') as $key) {
            expect($catalog)->toContain($key);
        }
    });

    it('filters to security events', function () {
        kindRow('user', 'password_changed');
        kindRow('firewall', 'rule_added');
        kindRow('database', 'created');

        $this->actingAs($this->admin)->getJson('/api/admin/activity-log?filter[security]=1')
            ->assertOk()->assertJsonCount(2, 'activity_log');
        $this->actingAs($this->admin)->getJson('/api/server/activity-log?filter[security]=1')
            ->assertOk()->assertJsonCount(1, 'activity_log')->assertJsonPath('activity_log.0.type', 'firewall');
    });
});

describe('server log filters for non-admins (FS-C11)', function () {
    it('lists the server scope\'s events and the kinds, to an activity_log viewer', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'activity_log');

        $response = $this->actingAs($viewer)->getJson('/api/server/activity-log/filters')->assertOk();

        expect($response->json('types'))->toEqualCanonicalizing(
            array_values(array_intersect(app(ActivityScopes::class)->types('server'), $response->json('types'))),
        )->and($response->json('types'))->toContain('firewall')->not->toContain('user')
            ->and($response->json('actions.firewall'))->toContain('rule_added')
            ->and(array_column($response->json('kinds'), 'value'))->toBe(ActivityKinds::KINDS);
    });

    it('is refused without the permission', function () {
        $this->actingAs(User::factory()->create())->getJson('/api/server/activity-log/filters')->assertForbidden();
    });
});
