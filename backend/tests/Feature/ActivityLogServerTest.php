<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityScopes;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Lang;

/*
 * The server activity log, and search across all three logs.
 *
 * Found testing the live panel (2026-09-23): the server log showed 96 of the
 * server's 216 events. It kept its own list of types — nine of the `server`
 * scope's nineteen — so PHP, system users, build tools and every site and
 * database event never reached anyone without admin access.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->viewer = User::factory()->create();
    grantPermission($this->viewer, 'activity_log', view: true, manage: false);
});

function logRow(string $type, string $action, array $properties = [], ?User $user = null): ActivityLog
{
    return ActivityLog::create([
        'user_id' => ($user ?? test()->admin)->id,
        'type' => $type,
        'action' => $action,
        'properties' => $properties,
    ]);
}

it('shows every type of the server scope, and nothing of the account scope', function () {
    $server = app(ActivityScopes::class)->types('server');

    foreach ($server as $type) {
        logRow($type, 'something');
    }
    logRow('user', 'logged_in');
    logRow('role', 'created');

    $types = collect($this->actingAs($this->viewer)
        ->getJson('/api/server/activity-log?per_page=100')
        ->assertOk()
        ->json('activity_log'))->pluck('type')->unique()->sort()->values()->all();

    expect($types)->toBe(collect($server)->sort()->values()->all());
});

it('includes the events the old list left out', function (string $type) {
    logRow($type, 'created');

    $this->actingAs($this->viewer)
        ->getJson('/api/server/activity-log')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
})->with(['php', 'system_user', 'build_tools', 'application', 'database', 'backup', 'storage_destination']);

it('refuses a page size it does not offer', function (mixed $perPage) {
    $this->actingAs($this->viewer)
        ->getJson("/api/server/activity-log?per_page={$perPage}")
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('per_page');
})->with([-5, 0, 100000]);

it('still needs the activity log permission', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/server/activity-log')
        ->assertForbidden();
});

/*
 * Search used to match type and action only, so a site, a version or an IP —
 * all stored in `properties` — found nothing.
 */
it('finds a row by what it was about', function (string $uri, string $search) {
    logRow('application', 'created', ['name' => 'f2b-test', 'domain' => 'f2b.example.com'], $this->viewer);
    logRow('php', 'installed', ['version' => '8.2'], $this->viewer);
    logRow('fail2ban', 'ip_banned', ['ip' => '203.0.113.9'], $this->viewer);
    logRow('node', 'installed', ['version' => '24.1.0'], $this->viewer);

    $user = str_starts_with($uri, '/api/admin') ? $this->admin : $this->viewer;

    $this->actingAs($user)
        ->getJson("{$uri}?search={$search}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
})->with([
    'admin, site name' => ['/api/admin/activity-log', 'f2b-test'],
    'admin, version' => ['/api/admin/activity-log', '8.2'],
    'server, IP' => ['/api/server/activity-log', '203.0.113.9'],
    'server, domain' => ['/api/server/activity-log', 'f2b.example'],
    'own, site name' => ['/api/activity-log', 'F2B-TEST'],
]);

it('still finds a row by its action and its actor', function () {
    logRow('php', 'installed', ['version' => '8.2']);

    $this->actingAs($this->admin)->getJson('/api/admin/activity-log?search=installed')->assertJsonPath('meta.total', 1);
    $this->actingAs($this->admin)->getJson('/api/admin/activity-log?search='.$this->admin->username)->assertJsonPath('meta.total', 1);
});

it('leaves retired events out of the filters, and keeps their sentences', function () {
    $filters = $this->actingAs($this->admin)->getJson('/api/admin/activity-log/filters')->assertOk()->json('actions');

    expect($filters['database'])->not->toContain('optimized')->not->toContain('repaired')->toContain('created')
        ->and($filters['application'])->not->toContain('php_unisolated')->toContain('php_isolated');

    // Old rows must still read as sentences.
    foreach ((array) config('activity.retired') as $key) {
        expect(Lang::has("activity.{$key}"))->toBeTrue();
    }
});
