<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Fake server state, held statically for the same reason ApplicationPhpTest
 * holds PoolFake statically: Pest's `test()` proxy does not reliably carry
 * writes made from inside an HTTP request back to the test.
 */
class FixPermissionsFake
{
    /** @var array<int, string> every command the panel ran */
    public static array $ran = [];

    /** Whether every command the panel runs succeeds. */
    public static bool $ok = true;

    /** Whether the site has an `.env` on disk. */
    public static bool $envExists = false;

    public static function reset(): void
    {
        self::$ran = [];
        self::$ok = true;
        self::$envExists = false;
    }
}

/*
 * "My site says permission denied" is the problem this button exists to
 * solve, now that sites run under their own Linux user rather than shared
 * www-data. So the tests that matter are: does it reset ownership across the
 * whole site, does it leave the site readable by the web server it still
 * needs to be readable by, and does it re-tighten the two paths that must
 * stay narrower than the rest of the tree.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'domain' => 'shop.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);

    FixPermissionsFake::reset();
});

function fakeFileServer(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        [$binary] = $args;

        FixPermissionsFake::$ran[] = implode(' ', $args);

        if ($binary === 'test' && ($args[1] ?? '') === '-f') {
            return Process::result(exitCode: FixPermissionsFake::$envExists ? 0 : 1);
        }

        return Process::result(
            exitCode: FixPermissionsFake::$ok ? 0 : 1,
            errorOutput: FixPermissionsFake::$ok ? '' : 'permission denied',
        );
    });
}

function fixUrl(): string
{
    return '/api/applications/'.test()->application->id.'/fix-permissions';
}

it('resets ownership and modes across the whole site', function () {
    fakeFileServer();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    $root = '/home/siteowner/shop.test';

    expect(FixPermissionsFake::$ran)->toContain("chown -R siteowner:siteowner {$root}")
        // 0755/0644, not tighter: nginx serves static assets straight off disk
        // as its own user, and is not a member of the site's group. Anything
        // tighter breaks every image and script on the site.
        ->and(collect(FixPermissionsFake::$ran)->contains(fn (string $c) => str_contains($c, "find {$root} -type d -exec chmod 0755")))->toBeTrue()
        ->and(collect(FixPermissionsFake::$ran)->contains(fn (string $c) => str_contains($c, "find {$root} -type f -exec chmod 0644")))->toBeTrue();
});

it('logs the action', function () {
    fakeFileServer();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(ActivityLog::where('type', 'application')->where('action', 'permissions_fixed')->exists())->toBeTrue();
});

it('re-tightens .env back to 0600 when one exists', function () {
    fakeFileServer();
    FixPermissionsFake::$envExists = true;

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(FixPermissionsFake::$ran)->toContain('chmod 0600 /home/siteowner/shop.test/.env');
});

it('does not touch .env when the site has none', function () {
    fakeFileServer();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(FixPermissionsFake::$ran)->not->toContain('chmod 0600 /home/siteowner/shop.test/.env');
});

it('re-tightens the session directory once the site is isolated', function () {
    fakeFileServer();
    // Not mass-assignable (isolation only happens through the isolate
    // endpoint), so set the column directly rather than via update().
    $this->application->isolated_at = now();
    $this->application->save();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(FixPermissionsFake::$ran)->toContain('chmod -R 0700 /home/siteowner/shop.test/.panel/sessions');
});

it('leaves the session directory alone for a site that is not isolated', function () {
    fakeFileServer();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(collect(FixPermissionsFake::$ran)->contains(fn (string $c) => str_contains($c, '.panel/sessions')))->toBeFalse();
});

it('reports a server failure and does not log success', function () {
    fakeFileServer();
    FixPermissionsFake::$ok = false;

    $this->actingAs($this->admin)->postJson(fixUrl())->assertStatus(500);

    expect(ActivityLog::where('action', 'permissions_fixed')->exists())->toBeFalse();
});

describe('permissions', function () {
    it('refuses a viewer who cannot manage', function () {
        fakeFileServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)->postJson(fixUrl())->assertForbidden();
    });

    it('denies an unauthenticated caller', function () {
        fakeFileServer();

        $this->postJson(fixUrl())->assertUnauthorized();
    });
});
