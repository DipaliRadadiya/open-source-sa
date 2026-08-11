<?php

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Php\PoolManager;
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

it('can put a site back on the shared pool', function () {
    fakePhpServer();
    $this->actingAs($this->admin)->postJson(phpUrl('/isolate'))->assertOk();

    $this->actingAs($this->admin)->deleteJson(phpUrl('/isolate'))->assertOk();

    expect($this->application->fresh()->isolated_at)->toBeNull()
        ->and(poolFile())->toBeNull();
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
