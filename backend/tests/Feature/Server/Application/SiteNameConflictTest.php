<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\SlugConflict;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The site name is a filename.
 *
 * It names the PHP-FPM pool, the web server vhost and — on OpenLiteSpeed —
 * the vhost directory, and uniqueness was only ever asked of the
 * `applications` table. That table knows what the panel has made and nothing
 * about what is already on the disk, so `www` (the pool the distro ships) and
 * `panel` (the panel's own vhost) were both accepted and both overwrote the
 * file they landed on. Reachable from create and from rename alike.
 *
 * What these pin: the refusal happens, it names the right collision, it does
 * not fire on a site's own files, and — the one that matters most — a server
 * that cannot answer the probe can still make sites.
 */
class ConflictFake
{
    /** @var array<int, string> paths that exist on the fake server */
    public static array $present = [];

    /** When set, `ls` gets no answer at all: sudo refused. */
    public static bool $refused = false;

    /** @var array<int, string> every directory actually listed */
    public static array $probed = [];

    public static function reset(): void
    {
        self::$present = [];
        self::$refused = false;
        self::$probed = [];
    }
}

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

    ConflictFake::reset();

    // The versions this "server" has. Without this the probe asks whatever
    // happens to be installed on the machine running the suite, and the
    // multi-version test measures nothing.
    $versions = Mockery::mock(PhpVersionManager::class);
    $versions->shouldReceive('versions')->andReturn(['8.4', '8.3']);
    app()->instance(PhpVersionManager::class, $versions);

    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'ls') {
            $dir = $args[2] ?? '';
            ConflictFake::$probed[] = $dir;

            if (ConflictFake::$refused) {
                return Process::result(errorOutput: 'sudo: a password is required', exitCode: 1);
            }

            // Only the entries of the directory asked for, the way `ls -1`
            // really answers — a fake that returned full paths would let a
            // basename comparison pass while measuring nothing.
            $entries = [];

            foreach (ConflictFake::$present as $path) {
                if (dirname($path) === $dir) {
                    $entries[] = basename($path);
                }
            }

            return Process::result(output: implode("\n", $entries));
        }

        return Process::result(exitCode: 0);
    });
});

function conflictFor(string $name, ?Application $ignore = null): ?string
{
    return app(SlugConflict::class)->for(Application::uniqueSlug($name, $ignore?->id), $ignore);
}

function conflictSite(string $name, string $slug): Application
{
    return Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => $name,
        'slug' => $slug,
        'domain' => $slug.'.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);
}

it('refuses a name whose slug is the distro PHP pool', function () {
    // The real one: `www.conf` is shipped by the php-fpm package, and every
    // site that is not isolated reaches the pool it defines.
    ConflictFake::$present = ['/etc/php/8.4/fpm/pool.d/www.conf'];

    expect(conflictFor('www'))->toBe('pool')
        // Case is not a defence — `Str::slug` lowercases, and one file is one
        // file.
        ->and(conflictFor('WWW'))->toBe('pool');
});

it('refuses a name whose slug is the panel\'s own vhost', function () {
    // Worse than the pool case: the reply to this request would be the last
    // thing the API ever said.
    ConflictFake::$present = ['/etc/nginx/sites-available/panel.conf'];

    expect(conflictFor('panel'))->toBe('vhost');
});

it('checks the pool of every installed PHP version, not just the default', function () {
    // The version is per site and changeable afterwards, so a collision under
    // 8.3 is a collision.
    ConflictFake::$present = ['/etc/php/8.3/fpm/pool.d/www.conf'];

    expect(conflictFor('www'))->toBe('pool');
});

it('leaves an ordinary name alone', function () {
    ConflictFake::$present = ['/etc/php/8.4/fpm/pool.d/www.conf'];

    expect(conflictFor('My Shop'))->toBeNull();
});

it('does not trip over a site\'s own files when it is renamed to its own name', function () {
    $application = conflictSite('Shop', 'shop');

    // Everything this site owns is on disk, as it should be.
    ConflictFake::$present = [
        '/etc/php/8.4/fpm/pool.d/shop.conf',
        '/etc/nginx/sites-available/shop.conf',
    ];

    expect(conflictFor('Shop', $application))->toBeNull();
});

it('still refuses when an existing site is renamed onto someone else\'s file', function () {
    $application = conflictSite('Shop', 'shop');

    ConflictFake::$present = ['/etc/nginx/sites-available/panel.conf'];

    expect(conflictFor('panel', $application))->toBe('vhost');
});

it('lets sites be created on a server whose probe cannot answer', function () {
    // The failure that would matter more than the bug. A sudo grant older
    // than this build answers nothing, and "I could not look" must not become
    // "no site may be created here".
    ConflictFake::$refused = true;
    ConflictFake::$present = ['/etc/php/8.4/fpm/pool.d/www.conf'];

    expect(conflictFor('www'))->toBeNull()
        // It really did try — a fake that never probed would pass this test
        // while measuring nothing.
        ->and(ConflictFake::$probed)->not->toBeEmpty();
});

it('refuses the create request itself, in the user\'s language', function () {
    ConflictFake::$present = ['/etc/nginx/sites-available/panel.conf'];

    $this->actingAs($this->admin)
        ->postJson('/api/applications', [
            'site_type' => 'php',
            'name' => 'panel',
            'domain' => 'panel-clash.test',
            'system_user_id' => $this->systemUser->id,
            'php_version' => '8.4',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name')
        ->assertJsonFragment(['name' => [__('validation.site_name_taken_vhost', ['name' => 'panel'])]]);

    expect(Application::query()->where('slug', 'panel')->exists())->toBeFalse();
});

it('cannot be reached by a rename at all, since the name is immutable', function () {
    // This used to assert that the *collision* was refused on rename. It would
    // still pass, and it would be measuring the wrong thing: `name` is now
    // refused on this endpoint whatever its value
    // ({@see \App\Http\Requests\Server\Application\UpdateApplicationRequest}),
    // so a slug can no longer move and the disk check has nothing to catch
    // here. Renamed so it does not claim otherwise.
    $application = conflictSite('Shop', 'shop');

    ConflictFake::$present = ['/etc/php/8.4/fpm/pool.d/www.conf'];

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$application->id}", ['name' => 'www'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    // An ordinary name is refused for the same reason — the guard is not what
    // is doing the work.
    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$application->id}", ['name' => 'Perfectly Fine'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    expect($application->fresh()->slug)->toBe('shop')
        ->and($application->fresh()->name)->toBe('Shop');
});

it('still accepts the runtime fields the panel really sends to this endpoint', function () {
    // The one frontend caller of `PUT /applications/{id}` is
    // `updateApplicationRuntime`, which sends these two and nothing else.
    // Prohibiting `name` must not cost it.
    $application = conflictSite('Node Site', 'node-site');
    $application->forceFill(['serving_profile' => 'node', 'php_version' => null])->save();

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$application->id}", [
            'start_command' => 'node server.js',
            'app_port' => 3999,
        ])
        ->assertOk();

    expect($application->fresh()->start_command)->toBe('node server.js');
});

it('steps over a taken name instead of refusing where there is nobody to ask', function () {
    // Clone and staging have no user in front of them, so they suffix past
    // the collision the way they always have for a name another site holds.
    ConflictFake::$present = ['/etc/php/8.4/fpm/pool.d/www.conf'];

    $slug = Application::uniqueSlug(
        'www',
        alsoTaken: fn (string $slug): bool => app(SlugConflict::class)->for($slug) !== null,
    );

    expect($slug)->toBe('www-2');
});

it('does not reach the server at all without that predicate', function () {
    // Every already-validated caller keeps the cheap path: the rule has just
    // asked the disk, and asking again on the way to the same answer is a
    // second round-trip per site created.
    Application::uniqueSlug('www');

    expect(ConflictFake::$probed)->toBeEmpty();
});

it('claims the vhost directory on OpenLiteSpeed and the file everywhere else', function () {
    // OLS's unit is a directory named after the slug, holding the vhost file;
    // nginx and Apache put a single `{slug}.conf` in a shared directory. The
    // probe has to ask about the right one — and asking about the parent
    // unconditionally means asking about `sites-available` itself, which
    // exists on every server and would refuse every name anyone could type.
    ConflictFake::$present = ['/etc/nginx/sites-available/panel.conf'];

    expect(conflictFor('panel'))->toBe('vhost')
        ->and(ConflictFake::$probed)->toContain('/etc/nginx/sites-available')
        // Never the level above, which holds nginx.conf and every other site.
        ->and(ConflictFake::$probed)->not->toContain('/etc/nginx');

    // Now the same question on OpenLiteSpeed, where the panel's own `panel`
    // and `panel-api` are directories rather than files.
    ServerCapability::query()->delete();
    ServerCapability::create(['web_server' => 'openlitespeed', 'source' => 'detected']);
    config(['server.web_server_drivers.openlitespeed.vhost_root' => '/usr/local/lsws/conf/vhosts']);
    // The capability is memoised per request, so the manager alone is not
    // enough — forget the thing it asks as well.
    app()->forgetInstance(ServerCapabilities::class);
    app()->forgetInstance(WebServerManager::class);

    ConflictFake::reset();
    // The entry in the vhosts directory *is* the site's directory.
    ConflictFake::$present = ['/usr/local/lsws/conf/vhosts/panel'];

    expect(conflictFor('panel'))->toBe('vhost')
        ->and(ConflictFake::$probed)->toContain('/usr/local/lsws/conf/vhosts');
});

it('gives up on the disk rather than looping forever', function () {
    // A predicate that answers "taken" to everything used to spin this loop
    // at 100% CPU, incrementing a suffix with no end — which is exactly what
    // `Process::fake()` with no handler produces, since it answers success to
    // every command. It hung the whole suite for forty minutes.
    //
    // A slug that might still collide is the acceptable failure here. An
    // application that never answers again is not.
    $slug = Application::uniqueSlug('www', alsoTaken: fn (): bool => true);

    expect($slug)->toBe('www-'.(Application::SLUG_DISK_ATTEMPTS + 1));
});
