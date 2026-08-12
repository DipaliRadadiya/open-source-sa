<?php

use App\Contracts\WebServerDriver;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Fake server state. Held statically because Pest's `test()` proxy does not
 * reliably carry writes made from inside an HTTP request back to the test.
 */
class PoolFake
{
    /** @var array<string, string> path => contents */
    public static array $files = [];

    /** @var array<int, string> every command the panel ran */
    public static array $ran = [];

    /** Whether `php-fpm -t` accepts the configuration. */
    public static bool $configValid = true;

    /** Whether `systemctl reload` succeeds. */
    public static bool $reloadOk = true;

    public static function reset(): void
    {
        self::$files = [];
        self::$ran = [];
        self::$configValid = true;
        self::$reloadOk = true;
    }
}

/*
 * The whole point of this feature is that a PHP site stops running as
 * www-data. So the tests that matter are: does the pool actually name the
 * site's user, does a bad pool file reach the daemon (it must not), and does
 * a site that was working keep working when something fails.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

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

    PoolFake::reset();
});

function fakePhpServer(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        [$binary] = $args;

        PoolFake::$ran[] = implode(' ', $args);

        if ($binary === 'tee') {
            PoolFake::$files[$args[1] ?? ''] = $process->input ?? '';

            return Process::result(exitCode: 0);
        }

        if ($binary === 'cat') {
            $path = $args[1] ?? '';

            return array_key_exists($path, PoolFake::$files)
                ? Process::result(output: PoolFake::$files[$path])
                : Process::result(errorOutput: 'No such file', exitCode: 1);
        }

        if ($binary === 'test') {
            return Process::result(exitCode: array_key_exists($args[2] ?? '', PoolFake::$files) ? 0 : 1);
        }

        if ($binary === 'rm') {
            unset(PoolFake::$files[$args[2] ?? $args[1] ?? '']);

            return Process::result(exitCode: 0);
        }

        if (str_starts_with($binary, 'php-fpm')) {
            return Process::result(exitCode: PoolFake::$configValid ? 0 : 1,
                errorOutput: PoolFake::$configValid ? '' : 'ERROR: unknown entry');
        }

        if ($binary === 'systemctl' && ($args[1] ?? '') === 'reload') {
            return Process::result(exitCode: PoolFake::$reloadOk ? 0 : 1);
        }

        return Process::result(exitCode: 0);
    });
}

function phpUrl(string $suffix = ''): string
{
    return '/api/applications/'.test()->application->id.'/php'.$suffix;
}

function poolFile(): ?string
{
    foreach (PoolFake::$files as $path => $contents) {
        if (str_contains($path, 'pool.d/')) {
            return $contents;
        }
    }

    return null;
}

it('reports a site as sharing the server pool until it is isolated', function () {
    fakePhpServer();

    $response = $this->actingAs($this->admin)->getJson(phpUrl())->assertOk();

    // The honest starting state, and the reason this feature exists.
    expect($response->json('php.isolated'))->toBeFalse()
        ->and($response->json('php.runs_as'))->toBe('www-data');
});

it('gives the site its own pool running as its own user', function () {
    fakePhpServer();

    $response = $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

    expect($response->json('php.isolated'))->toBeTrue()
        ->and($response->json('php.runs_as'))->toBe('siteowner');

    $pool = poolFile();

    // The two lines that are the entire feature.
    expect($pool)->toContain('user = siteowner')
        ->and($pool)->toContain('group = siteowner')
        ->and($pool)->not->toContain('user = www-data');

    // Its own socket, named from the slug (falling back to the domain here,
    // since the fixture sets no slug) — a shared path would silently orphan
    // whichever pool started first.
    expect($pool)->toContain('listen = /run/php/shop.sock')
        ->and($pool)->toContain('[shop]');
});

it('keeps the session directory inside the site', function () {
    fakePhpServer();
    $this->actingAs($this->admin)->postJson(phpUrl('/isolate'));

    // PHP's default session directory belongs to www-data. A site that stops
    // being www-data cannot write there, and every login on it breaks with no
    // obvious cause — so the path has to move with the user.
    expect(poolFile())->toContain('session.save_path] = /home/siteowner/shop/.panel/sessions');
});

it('bounds a memory leak with max_requests', function () {
    fakePhpServer();
    $this->actingAs($this->admin)->postJson(phpUrl('/isolate'));

    expect(poolFile())->toContain('pm.max_requests = 500');
});

describe('when the configuration is bad', function () {
    it('never reloads a configuration php-fpm rejected', function () {
        fakePhpServer();
        PoolFake::$configValid = false;

        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertStatus(422);

        // The reason this ordering exists: a pool file FPM cannot parse stops
        // the daemon starting, which takes down every PHP site on the box.
        // Testing first means a bad file never reaches a reload.
        expect(collect(PoolFake::$ran)->contains(fn (string $c) => str_contains($c, 'systemctl reload')))
            ->toBeFalse();

        // And the file is gone, so the next reload for any other reason does
        // not pick it up.
        expect(poolFile())->toBeNull();
    });

    it('leaves the site un-isolated and still served', function () {
        fakePhpServer();
        PoolFake::$configValid = false;

        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertStatus(422);

        expect($this->application->fresh()->isolated_at)->toBeNull();

        // Still pointing at the shared socket, exactly as before.
        expect(app(PoolManager::class)->socketFor($this->application->fresh()))
            ->toBe('/run/php/php8.4-fpm.sock');
    });

    it('restores the previous pool when the reload fails', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        PoolFake::$reloadOk = false;

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['memory_limit' => '1G'])
            ->assertStatus(422);

        // The file on disk is what was working a moment ago, not the change
        // that could not be loaded. Compared by content rather than byte-for-
        // byte: the writer normalises trailing whitespace, and asserting on
        // that would be testing the writer rather than the rollback.
        expect(poolFile())->toContain('memory_limit] = 256M')
            ->and(poolFile())->not->toContain('memory_limit] = 1G');

        // And the settings row did not keep a value the server rejected.
        expect(ApplicationPhpSettings::first()?->memory_limit)->not->toBe('1G');
    });
});

describe('settings', function () {
    it('writes what was chosen into the pool', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'));

        $this->actingAs($this->admin)->putJson(phpUrl(), [
            'memory_limit' => '512M',
            'max_execution_time' => 120,
            'pm_type' => 'dynamic',
            'pm_max_children' => 8,
        ])->assertOk();

        $pool = poolFile();

        expect($pool)->toContain('memory_limit] = 512M')
            ->and($pool)->toContain('max_execution_time] = 120')
            ->and($pool)->toContain('pm = dynamic')
            ->and($pool)->toContain('pm.max_children = 8')
            // Derived, not asked for — and only present for `dynamic`.
            ->and($pool)->toContain('pm.start_servers = 4');
    });

    it('leaves the spare-server settings out for an ondemand pool', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'));

        // php-fpm refuses a pool that sets dynamic-only directives, so this is
        // not tidiness — it is whether the pool starts.
        expect(poolFile())->not->toContain('pm.start_servers')
            ->and(poolFile())->toContain('pm.process_idle_timeout');
    });

    it('includes the session path when open_basedir is on', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'));

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true])
            ->assertOk();

        // Restricting file access without allowing the session directory
        // breaks every login on the site.
        expect(poolFile())->toContain('open_basedir] = /home/siteowner/shop:/home/siteowner/shop/.panel/sessions:/tmp');
    });

    it('refuses a section header in the free-text directives', function () {
        fakePhpServer();

        // `[another]` inside the file would silently declare a second pool.
        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['additional_directives' => "[another]\nuser = root"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('additional_directives');
    });

    it('refuses anything that is not a function list', function () {
        fakePhpServer();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['disable_functions' => 'exec; rm -rf /'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('disable_functions');
    });

    it('caps how many workers can be asked for', function () {
        fakePhpServer();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['pm_max_children' => 5000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pm_max_children');
    });
});

describe('memory budget', function () {
    it('reports what the settings would cost', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'));

        $memory = $this->actingAs($this->admin)->getJson(phpUrl())->json('php.memory');

        // 256M × 5 workers. The number every panel lets you set and none of
        // them show you.
        expect($memory['this_site'])->toBe(256 * 1024 * 1024 * 5)
            ->and($memory['total'])->toBeGreaterThan(0);
    });

    it('notices when the sites together exceed the machine', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'));

        $this->actingAs($this->admin)->putJson(phpUrl(), [
            'memory_limit' => '1G',
            'pm_max_children' => 100,
        ])->assertOk();

        // 100 GB committed on any real machine. A warning, not a block —
        // someone deliberately over-committing a dev box is allowed to.
        expect($this->actingAs($this->admin)->getJson(phpUrl())->json('php.memory.over_committed'))
            ->toBeTrue();
    });
});

it('offers no way back onto the shared pool', function () {
    fakePhpServer();
    $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

    // Removed deliberately, not forgotten. Going back means running as the
    // web server's own account again, which is exactly the cross-site `.env`
    // read pool isolation exists to close — a worse outcome than whatever the
    // un-isolate button was going to rescue.
    $this->actingAs($this->admin)->deleteJson(phpUrl('/isolate'))->assertStatus(405);

    expect($this->application->fresh()->isolated_at)->not->toBeNull()
        ->and(poolFile())->not->toBeNull();
});

it('refuses to store pool limits for a site that has no pool', function () {
    fakePhpServer();

    // They are enforced by the pool file, so without one they would be saved
    // and never applied — a 200 that changes nothing on the server.
    $this->actingAs($this->admin)
        ->putJson(phpUrl(), ['memory_limit' => '512M'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('settings');

    expect(ApplicationPhpSettings::where('application_id', $this->application->id)->first()?->memory_limit)
        ->toBeNull();
});

it('still allows a version change on a site that has no pool', function () {
    fakePhpServer();

    // The version is carried by the vhost, not the pool, so it means
    // something either way — refusing it would block a legitimate change.
    $this->actingAs($this->admin)
        ->putJson(phpUrl(), ['php_version' => '8.3'])
        ->assertOk();

    expect($this->application->fresh()->php_version)->toBe('8.3');
});

describe('php:isolate-all', function () {
    it('converts every site still on the shared pool', function () {
        fakePhpServer();

        expect($this->application->fresh()->isolated_at)->toBeNull();

        $this->artisan('php:isolate-all')->assertSuccessful();

        expect($this->application->fresh()->isolated_at)->not->toBeNull()
            ->and(poolFile())->toContain('siteowner');
    });

    it('leaves an already-isolated site alone', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();
        $isolatedAt = $this->application->fresh()->isolated_at;

        $this->artisan('php:isolate-all')->assertSuccessful();

        expect($this->application->fresh()->isolated_at->timestamp)->toBe($isolatedAt->timestamp);
    });

    it('carries on and reports the site when a pool will not write', function () {
        fakePhpServer();
        PoolFake::$configValid = false;

        // Exits 0 on purpose: the site is still serving, and failing here
        // would abort an otherwise-good panel update over one site.
        $this->artisan('php:isolate-all')->assertSuccessful();

        expect($this->application->fresh()->isolated_at)->toBeNull();
    });
});

it('says so when the pool has been edited by hand', function () {
    fakePhpServer();
    $this->actingAs($this->admin)->postJson(phpUrl('/isolate'));

    foreach (PoolFake::$files as $path => $contents) {
        if (str_contains($path, 'pool.d/')) {
            PoolFake::$files[$path] = $contents."\n; someone was here\n";
        }
    }

    // Said before they press save, not after their changes have gone.
    expect($this->actingAs($this->admin)->getJson(phpUrl())->json('php.managed'))->toBeFalse();
});

describe('permissions', function () {
    it('lets a viewer read but not change', function () {
        fakePhpServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_php', view: true, manage: false);

        $this->actingAs($user)->getJson(phpUrl())->assertOk();
        $this->actingAs($user)->postJson(phpUrl('/isolate'))->assertForbidden();
    });

    it('denies an unauthenticated caller', function () {
        fakePhpServer();

        $this->getJson(phpUrl())->assertUnauthorized();
    });

    it('is absent for a site that serves no PHP', function () {
        fakePhpServer();

        $static = Application::forceCreate([
            'system_user_id' => $this->application->system_user_id,
            'name' => 'Brochure',
            'slug' => 'brochure', 'domain' => 'brochure.test', 'site_type' => 'static',
            'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/applications/{$static->id}/php")
            ->assertNotFound();
    });
});

it('writes the vhost at the site\'s real document root when the version changes', function () {
    // The PHP screen republishes the vhost whenever the version moves. It
    // built the root itself instead of asking the provisioner, and built it
    // wrong: domain instead of slug, and no public_html — so the site would
    // be pointed at a directory that does not exist.
    fakePhpServer();

    $captured = [];

    $driver = Mockery::mock(WebServerDriver::class);
    $driver->shouldReceive('name')->andReturn('nginx');
    $driver->shouldReceive('apply')->andReturnUsing(function ($application, $root) use (&$captured) {
        $captured[] = $root;

        return new ServerOpsResult(true, 'ref', null);
    });
    $driver->shouldReceive('test')->andReturn(new ServerOpsResult(true, 'ref', null));
    $driver->shouldReceive('reload')->andReturn(new ServerOpsResult(true, 'ref', null));

    $manager = Mockery::mock(WebServerManager::class);
    $manager->shouldReceive('driver')->andReturn($driver);
    app()->instance(WebServerManager::class, $manager);

    $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

    $this->actingAs($this->admin)
        ->putJson(phpUrl(), ['php_version' => '8.3'])
        ->assertOk();

    expect($captured)->not->toBeEmpty()
        ->and(end($captured))->toBe(
            app(ApplicationProvisioner::class)
                ->documentRoot($this->application->fresh()->load('systemUser'))
        );
});

/*
 * open_basedir: the base paths are the site's, the extras are the user's.
 *
 * The legacy panel wrote `{home}/{name}:/var/lib/php/sessions:/tmp:{custom}`.
 * Two of those are deliberately different here — the app root is named by
 * slug, and the session directory is this site's own rather than a
 * server-wide one, which every site on the box could otherwise read.
 */
describe('open_basedir', function () {
    /** The rendered `php_admin_value[open_basedir]` line, without the comments. */
    function openBasedirLine(): ?string
    {
        return collect(explode("\n", (string) poolFile()))
            ->first(fn (string $l): bool => str_starts_with(trim($l), 'php_admin_value[open_basedir]'));
    }

    it('is left out entirely when the setting is off', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        // Absent, not empty: an empty value would forbid everything, where the
        // user asked for no restriction at all.
        expect(openBasedirLine())->toBeNull();
    });

    it('always allows the app root, the session directory and /tmp', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true])
            ->assertOk();

        $sessionPath = app(PoolManager::class)->sessionPath($this->application);

        // The directive itself, not the whole file — the template explains
        // PHP's default in a comment, and asserting against the file would
        // read that comment as configuration.
        $line = openBasedirLine();

        // Without the session directory, switching this on logs out every
        // visitor and keeps them logged out — the classic way open_basedir
        // gets blamed for the panel breaking a site.
        expect($line)->toContain('/home/siteowner/shop')
            ->toContain($sessionPath)
            ->toContain('/tmp')
            // The site's own session directory, never a shared one: naming
            // /var/lib/php/sessions would let every site on the server read
            // every other site's sessions.
            ->not->toContain('/var/lib/php/sessions');
    });

    it('appends the user\'s own directories', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), [
                'open_basedir_enabled' => true,
                'open_basedir_paths' => "/mnt/shared\n/srv/uploads",
            ])
            ->assertOk();

        expect(openBasedirLine())->toContain('/mnt/shared')
            ->toContain('/srv/uploads')
            // Still there — the extras add, they never replace.
            ->toContain('/tmp');
    });

    it('never writes an empty path component', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true, 'open_basedir_paths' => ''])
            ->assertOk();

        // The legacy template interpolated the column straight in, so an empty
        // one left a trailing colon. What PHP makes of an empty component is
        // version dependent and never what anyone intended.
        $line = openBasedirLine();

        expect($line)->not->toContain('::')
            ->and(rtrim((string) $line))->not->toEndWith(':');
    });

    it('refuses a relative path', function () {
        fakePhpServer();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true, 'open_basedir_paths' => 'srv/uploads'])
            ->assertJsonValidationErrors('open_basedir_paths');
    });

    it('refuses traversal', function () {
        fakePhpServer();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true, 'open_basedir_paths' => '/srv/../../etc'])
            ->assertJsonValidationErrors('open_basedir_paths');
    });

    it('refuses / , which would leave the setting on but enforcing nothing', function () {
        fakePhpServer();

        // The panel must not report a protection it is not applying. Turning
        // the toggle off is the honest way to get the same result.
        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true, 'open_basedir_paths' => '/'])
            ->assertJsonValidationErrors('open_basedir_paths');
    });

    it('reports the exact value the pool will contain', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true, 'open_basedir_paths' => '/mnt/shared'])
            ->assertOk();

        // The user supplies additions only, so a screen showing just their
        // input would be showing half the setting.
        $effective = $this->actingAs($this->admin)->getJson(phpUrl())->json('php.open_basedir_effective');

        expect($effective)->toContain('/mnt/shared')
            ->toContain('/tmp')
            ->and(poolFile())->toContain($effective);
    });
});

/*
 * Three answers about one setting, and they are allowed to disagree.
 *
 * `effective` is what the panel would write, `live` is what the file on disk
 * says, `recommended` is what switching it on would give. The panel is only
 * honest if it can show the second one when it differs from the first.
 */
describe('open_basedir, as reported', function () {
    it('recommends the base paths when the setting is off', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        $php = $this->actingAs($this->admin)->getJson(phpUrl())->json('php');

        // Nothing enforced, but the screen can still offer the value rather
        // than asking someone to work out what a safe one looks like.
        expect($php['open_basedir_effective'])->toBeNull()
            ->and($php['open_basedir_live'])->toBeNull()
            ->and($php['open_basedir_recommended'])
            ->toContain('/home/siteowner/shop')
            ->toContain('/tmp');
    });

    it('reads the value the pool file actually sets', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true, 'open_basedir_paths' => '/mnt/shared'])
            ->assertOk();

        $php = $this->actingAs($this->admin)->getJson(phpUrl())->json('php');

        expect($php['open_basedir_live'])->toBe($php['open_basedir_effective'])
            ->and($php['open_basedir_live'])->toContain('/mnt/shared');
    });

    it('reports what someone else set, not what we would have set', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();
        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true])
            ->assertOk();

        // Someone hand-edits the pool, or slips their own directive into the
        // additional-directives box — where it lands after ours and wins,
        // because FPM takes the last of a repeated key.
        foreach (PoolFake::$files as $path => $contents) {
            if (str_contains($path, 'pool.d/')) {
                PoolFake::$files[$path] = $contents."\nphp_admin_value[open_basedir] = /srv/somewhere-else\n";
            }
        }

        $php = $this->actingAs($this->admin)->getJson(phpUrl())->json('php');

        // The whole point: the panel must not claim a restriction PHP is not
        // applying.
        expect($php['open_basedir_live'])->toBe('/srv/somewhere-else')
            ->and($php['open_basedir_live'])->not->toBe($php['open_basedir_effective'])
            // And the existing drift flag says the file is no longer ours.
            ->and($php['managed'])->toBeFalse();
    });

    it('reports nothing live for a site with no pool of its own', function () {
        fakePhpServer();

        // Not isolated: there is no pool file, so there is nothing to read.
        // Null, not the recommendation — "unknown" and "none" are different
        // answers and the screen renders them differently.
        expect($this->actingAs($this->admin)->getJson(phpUrl())->json('php.open_basedir_live'))
            ->toBeNull();
    });
});

/*
 * Migrating a server must not quietly re-configure it.
 *
 * A box coming from another panel has real open_basedir values in its pool
 * files. The settings row that replaces them starts empty, so writing our own
 * pool would drop a restriction the owner deliberately set — a silent
 * loosening, during exactly the operation people trust least.
 */
describe('adopting an existing open_basedir', function () {
    /** Put a foreign pool file on disk, the way a migrated server would have. */
    function seedForeignPool(string $openBasedir): void
    {
        $path = '/etc/php/8.4/fpm/pool.d/'.test()->application->slug.'.conf';

        PoolFake::$files[$path] = "[legacy]\nuser = siteowner\n"
            ."php_admin_value[open_basedir] = {$openBasedir}\n";
    }

    it('keeps the paths the old panel set', function () {
        fakePhpServer();
        seedForeignPool('/home/siteowner/shop:/mnt/legacy-share:/tmp');

        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        $php = $this->actingAs($this->admin)->getJson(phpUrl())->json('php');

        expect($php['settings']['open_basedir_enabled'])->toBeTrue()
            ->and($php['settings']['open_basedir_paths'])->toContain('/mnt/legacy-share')
            ->and($php['open_basedir_effective'])->toContain('/mnt/legacy-share');
    });

    it('does not import a server-wide session directory', function () {
        fakePhpServer();

        // The running panel's own format. Carried over verbatim it would let
        // this site read every other site's sessions — the isolation per-app
        // pools exist to give.
        seedForeignPool('/home/siteowner/shop:/var/lib/php/sessions:/tmp:/mnt/legacy-share');

        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        $effective = $this->actingAs($this->admin)->getJson(phpUrl())->json('php.open_basedir_effective');

        expect($effective)->toContain('/mnt/legacy-share')
            ->not->toContain('/var/lib/php/sessions')
            // Its own session directory is in there instead, so nothing it
            // legitimately needs was lost.
            ->toContain(app(PoolManager::class)->sessionPath($this->application));
    });

    it('leaves a site alone when the old pool restricted nothing', function () {
        fakePhpServer();
        // A pool file with no open_basedir at all is the common case, and
        // switching a restriction ON during a migration is its own surprise.
        PoolFake::$files['/etc/php/8.4/fpm/pool.d/shop.conf'] = "[legacy]\nuser = siteowner\n";

        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        expect($this->actingAs($this->admin)->getJson(phpUrl())->json('php.settings.open_basedir_enabled'))
            ->toBeFalse();
    });

    it('never overwrites a choice the user already made', function () {
        fakePhpServer();
        $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

        $this->actingAs($this->admin)
            ->putJson(phpUrl(), ['open_basedir_enabled' => true, 'open_basedir_paths' => '/mnt/mine'])
            ->assertOk();

        // Adoption is a one-time takeover of an unowned pool, not something
        // that re-reads the file and second-guesses the user afterwards.
        $settings = ApplicationPhpSettings::where('application_id', $this->application->id)->firstOrFail();

        expect(app(PoolManager::class)->adoptOpenBasedir($this->application, $settings)['adopted'])->toBeFalse()
            ->and($settings->fresh()->open_basedir_paths)->toBe('/mnt/mine');
    });
});
