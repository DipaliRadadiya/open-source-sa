<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Changing the web root has to change what the server actually serves.
 *
 * The column and its validation already existed; nothing applied them. A user
 * could move the web root, get a 200, and keep being served the old directory
 * until the next re-provision — the panel reporting a change it had not made.
 *
 * What these cover: the directory is created before anything points at it, the
 * vhost is tested before it is reloaded, a failed test puts the previous root
 * back, and a site that is pending or disabled stores the value without being
 * re-published (or, for a disabled site, resurrected).
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Blog',
        'slug' => 'blog',
        'domain' => 'blog.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);
});

function webRootHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/**
 * Every command that ran, in order.
 *
 * `Process::assertRan()` stops at the first process its callback accepts, so
 * it cannot be used to collect what ran — the fake records here instead.
 */
function webRootRecorder(): ArrayObject
{
    static $bag = null;

    return $bag ??= new ArrayObject;
}

/**
 * @param  bool  $testPasses  whether `nginx -t` succeeds.
 * @param  int  $folderTest  what `test -d` on the new web root answers: 0 there, 1 missing.
 */
function fakeWebRootServer(bool $testPasses = true, int $folderTest = 0, ?string $linkAt = null): void
{
    webRootRecorder()->exchangeArray([]);

    Process::fake(function ($process) use ($testPasses, $folderTest, $linkAt) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        webRootRecorder()->append($args);

        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-d') {
            return Process::result(exitCode: $folderTest);
        }

        // `test -L`: only the path named in $linkAt is a symlink.
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-L') {
            return Process::result(exitCode: ($args[2] ?? '') === $linkAt ? 0 : 1);
        }

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        return Process::result(exitCode: 0);
    });
}

function webRootUrl(): string
{
    return '/api/applications/'.test()->application->id.'/web-root';
}

/** Did a command run with these arguments, ignoring any `sudo` wrapper? */
function webRootRan(callable $matches): bool
{
    foreach (webRootRecorder() as $args) {
        if ($matches($args)) {
            return true;
        }
    }

    return false;
}

it('switches to an existing folder, re-renders the vhost and reloads', function () {
    fakeWebRootServer();

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => 'public'])
        ->assertOk()
        ->assertJsonPath('application.web_root', 'public');

    expect($this->application->fresh()->web_root)->toBe('public');

    // No mkdir or chown as root on the site user's path any more (WR-01):
    // both followed symlinks the user planted. The folder must exist (#8).
    // Nothing as root under public_html, which the site user controls; the
    // panel's own folders beside it (logs, .panel) are root-owned and locked.
    expect(webRootRan(fn ($args) => in_array($args[0] ?? '', ['mkdir', 'chown'], true)
        && str_contains(implode(' ', $args), '/public_html')))->toBeFalse();

    expect(webRootRan(fn ($args) => ($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t'))->toBeTrue();
});

it('refuses a web root reached through a symlink, at any depth, and touches nothing (WR-01)', function (string $webRoot, string $link) {
    // Old QA list WR-01: public_html/pub -> /opt/target, then web root "pub",
    // made root chown /opt/target to the site user.
    fakeWebRootServer(linkAt: $link);

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => $webRoot])
        ->assertUnprocessable()
        ->assertJsonPath('errors.web_root.0', __('validation.web_root_symlink', ['path' => $link]));

    expect($this->application->fresh()->web_root)->toBe('/')
        ->and(webRootRan(fn ($args) => in_array($args[0] ?? '', ['mkdir', 'chown', 'tee'], true)))->toBeFalse()
        ->and(webRootRan(fn ($args) => ($args[0] ?? '') === 'nginx'))->toBeFalse();
})->with([
    'the folder itself' => ['pub', '/home/siteowner/blog/public_html/pub'],
    'a folder above it' => ['pub/inner', '/home/siteowner/blog/public_html/pub'],
    'the last of two' => ['real/pub', '/home/siteowner/blog/public_html/real/pub'],
]);

it('refuses a folder that does not exist, and leaves the site alone', function () {
    // Junior re-test #8: `/etc` was saved as public_html/etc, created empty,
    // and the live site answered 403 with no warning.
    fakeWebRootServer(folderTest: 1);

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => 'etc'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.web_root.0', __('validation.web_root_missing', ['path' => '/home/siteowner/blog/public_html/etc']));

    expect($this->application->fresh()->web_root)->toBe('/')
        ->and(webRootRan(fn ($args) => in_array($args[0] ?? '', ['mkdir', 'chown', 'tee'], true)))->toBeFalse()
        ->and(webRootRan(fn ($args) => ($args[0] ?? '') === 'nginx'))->toBeFalse()
        ->and(ActivityLog::query()->where('action', 'web_root_changed')->count())->toBe(0);
});

it('refuses it on the generic application update too', function () {
    fakeWebRootServer(folderTest: 1);

    $this->withHeaders(webRootHeaders())
        ->putJson('/api/applications/'.$this->application->id, ['web_root' => 'etc'])
        ->assertJsonValidationErrors('web_root');

    expect($this->application->fresh()->web_root)->toBe('/');
});

it('does not read a check that could not run as "missing"', function () {
    // A refused sudo or a timeout is a fault, not an answer.
    fakeWebRootServer(folderTest: 2);

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => 'public'])
        ->assertStatus(500);

    expect($this->application->fresh()->web_root)->toBe('/');
});

it('does not read a refused sudo as "missing" either', function () {
    // sudo refusing the command exits 1 too — the same code as "not there".
    config(['server.privilege.sudo' => true]);

    Process::fake(fn ($process) => ($process->command[2] ?? '') === 'test'
        ? Process::result(errorOutput: 'sudo: a password is required', exitCode: 1)
        : Process::result());

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => 'public'])
        ->assertStatus(500);

    expect($this->application->fresh()->web_root)->toBe('/');
});

it('puts the previous web root back when the config test fails', function () {
    fakeWebRootServer(testPasses: false);

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => 'public'])
        ->assertStatus(500)
        ->assertJsonPath('code', 'server_operation_failed');

    // Not reloaded, and not stored: the site is still being served from the
    // root it was a moment ago, and the record still says so.
    expect($this->application->fresh()->web_root)->toBe('/');
});

it('logs the change', function () {
    fakeWebRootServer();

    $this->withHeaders(webRootHeaders())->putJson(webRootUrl(), ['web_root' => 'public'])->assertOk();

    $log = ActivityLog::query()->where('type', 'application')->where('action', 'web_root_changed')->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['web_root'])->toBe('public');
});

it('does nothing at all when the web root has not changed', function () {
    fakeWebRootServer();

    // `/`, `` and `/` are the same web root — a form that round-trips the
    // current value must not rewrite and reload the vhost.
    $this->withHeaders(webRootHeaders())->putJson(webRootUrl(), ['web_root' => '/'])->assertOk();

    Process::assertNothingRan();

    expect(ActivityLog::query()->where('action', 'web_root_changed')->count())->toBe(0);
});

it('stores the value without touching the server for an application that is not provisioned yet', function () {
    fakeWebRootServer();

    $this->application->forceFill(['status' => 'pending'])->save();

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => 'public'])
        ->assertOk();

    expect($this->application->fresh()->web_root)->toBe('public');

    // There is no config on the server yet, so there is nothing to move.
    Process::assertNothingRan();
});

it('does not put a disabled site back online', function () {
    fakeWebRootServer();

    $this->application->forceFill(['disabled_at' => now()])->save();

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => 'public'])
        ->assertOk();

    expect($this->application->fresh()->web_root)->toBe('public');

    // A disabled site's vhost deliberately points at the disabled page.
    // Re-publishing the real one here would bring the site back as a side
    // effect of an unrelated setting.
    Process::assertNothingRan();
});

it('refuses a web root that climbs out of the site', function (string $webRoot) {
    Process::fake();

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => $webRoot])
        ->assertJsonValidationErrors('web_root');

    Process::assertNotRan(fn ($p) => in_array($p->command[0] ?? '', ['mkdir', 'chown'], true));
})->with(['../../../../etc', 'public/../../../../etc', '..', '$(whoami)', "public\nnewline"]);

it('refuses a user without manage on applications', function () {
    fakeWebRootServer();

    $user = User::factory()->create();
    $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer']);
    $user->roles()->attach($role);

    $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
        ->putJson(webRootUrl(), ['web_root' => 'public'])
        ->assertForbidden();

    expect($this->application->fresh()->web_root)->toBe('/');
});

it('applies the change when the web root arrives on the generic application update', function () {
    fakeWebRootServer();

    // The frontend's application form posts the whole record. Storing the
    // column there without applying it is the exact bug this feature fixes,
    // so that path routes through the same manager.
    $this->withHeaders(webRootHeaders())
        ->putJson('/api/applications/'.$this->application->id, ['web_root' => 'public'])
        ->assertOk();

    expect($this->application->fresh()->web_root)->toBe('public')
        ->and(webRootRan(fn ($args) => ($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t'))->toBeTrue();
});
