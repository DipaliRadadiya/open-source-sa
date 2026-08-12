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

/** @param  bool  $testPasses  whether `nginx -t` succeeds. */
function fakeWebRootServer(bool $testPasses = true): void
{
    webRootRecorder()->exchangeArray([]);

    Process::fake(function ($process) use ($testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        webRootRecorder()->append($args);

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

it('creates the new directory, re-renders the vhost and reloads', function () {
    fakeWebRootServer();

    $this->withHeaders(webRootHeaders())
        ->putJson(webRootUrl(), ['web_root' => 'public'])
        ->assertOk()
        ->assertJsonPath('application.web_root', 'public');

    expect($this->application->fresh()->web_root)->toBe('public');

    // The directory has to exist before the vhost points at it — otherwise
    // every request is a 403 that looks like a permissions bug.
    // Slug-based and under public_html — the layout the provisioner has
    // always written. These asserted `{home}/{domain}/{web_root}`, which is
    // the string the PHP screen used to build and no directory that exists.
    expect(webRootRan(fn ($args) => ($args[0] ?? '') === 'chown'
        && in_array('/home/siteowner/blog/public_html/public', $args, true)))->toBeTrue();

    // No `.panel` inside the new root: the panel's own files live above the
    // document root now, so moving the root does not move them.
    expect(webRootRan(fn ($args) => ($args[0] ?? '') === 'mkdir'
        && in_array('/home/siteowner/blog/public_html/public', $args, true)))->toBeTrue();

    expect(webRootRan(fn ($args) => ($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t'))->toBeTrue();
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
