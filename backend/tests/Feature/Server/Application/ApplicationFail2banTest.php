<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * Per-application fail2ban: the commercial-style raw-INI variant.
 *
 * Three endpoints (GET / POST / DELETE /api/applications/{id}/fail2ban),
 * one auto-migrate from the previous structured schema, one rendered
 * jail + filter file written to /etc/fail2ban/{jail,filter}.d/. What
 * matters here: the test before apply catches a bad config, the
 * auto-migrate rebuilds INI from old structured columns on first GET,
 * and disable() leaves no trace on disk.
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

    // Stage fail2ban directories in temp so the manager writes files we can
    // inspect without touching the real /etc/fail2ban. The config matches
    // the production layout — same paths the install script would use — so
    // the tests exercise the same code paths the live server does.
    $this->jailD = sys_get_temp_dir().'/sv-oss-f2b-jail-'.getmypid();
    $this->filterD = sys_get_temp_dir().'/sv-oss-f2b-filter-'.getmypid();
    @mkdir($this->jailD, 0755, true);
    @mkdir($this->filterD, 0755, true);
    config(['server.fail2ban.jail_d' => $this->jailD]);
    config(['server.fail2ban.filter_d' => $this->filterD]);
});

afterEach(function () {
    foreach ([$this->jailD, $this->filterD] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        foreach (glob($dir.'/*.conf') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($dir.'/*.local') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

function appFail2banHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/**
 * Fake the fail2ban-client invocations. `$testOk=false` makes the `-t`
 * validation step fail (the controller then refuses to write the file).
 *
 * `$writes` is passed by reference and is populated with any `tee` writes
 * the fake observes, indexed by absolute path. The caller reads it after
 * the request to assert that the on-disk files match what was submitted.
 *
 * @param  array<string, string>  $writes  jail/filter path => written content
 */
function fakeAppFail2ban(bool $testOk = true, array &$writes = []): void
{
    Process::fake(function ($process) use ($testOk, &$writes) {
        $args = $process->command[0] === 'sudo'
            ? array_slice($process->command, 2)
            : $process->command;

        if (($args[0] ?? '') === 'tee') {
            $writes[$args[1] ?? ''] = (string) $process->input;

            return Process::result(exitCode: 0);
        }

        // `rm -f <path>` is what disableForApp() runs. Process::fake does
        // not actually delete the file, so the disable test would never see
        // the file leave disk without us mirroring the call here.
        if (($args[0] ?? '') === 'rm' && in_array(($args[1] ?? ''), ['-f', '--force'], true)) {
            $target = $args[2] ?? null;
            if ($target !== null && file_exists($target)) {
                @unlink($target);
            }

            return Process::result(exitCode: 0);
        }

        if (($args[0] ?? '') === 'fail2ban-client') {
            return match ($args[1] ?? '') {
                'ping' => Process::result(output: 'Server replied: pong'),
                '-t' => Process::result(
                    output: $testOk ? "OK: configuration test successful\n" : "ERROR: Invalid config\n",
                    exitCode: $testOk ? 0 : 1,
                ),
                'reload' => Process::result(exitCode: 0),
                default => Process::result(exitCode: 0),
            };
        }

        return Process::result(exitCode: 0);
    });
}

function appFail2banUrl(): string
{
    return '/api/applications/'.test()->application->id.'/fail2ban';
}

/**
 * Create an application the same way the production CreateApplication action
 * does — most importantly, sets `slug` from the name via uniqueSlug(). Without
 * a slug the fail2ban jail name falls back to the domain (`sVoss-shop.test`)
 * and the test assertions miss the cleaner `sVoss-shop` form.
 */
function createFail2banApp(string $name, string $domain, string $siteType = 'php', array $extra = []): Application
{
    // forceCreate: `slug` is not in $fillable (it is server-derived) but the
    // test wants the resolved slug on the row so the jail name is sVoss-shop,
    // not sVoss-shop.test. CreateApplication uses forceCreate too for the same
    // reason — we mirror that here.
    return Application::forceCreate(array_merge([
        'system_user_id' => test()->systemUser->id,
        'name' => $name,
        'domain' => $domain,
        'slug' => Application::uniqueSlug($name),
        'site_type' => $siteType,
        'serving_profile' => $siteType,
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ], $extra));
}

it('returns null fail2ban with templates for a never-configured application', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');

    $this->withHeaders(appFail2banHeaders())
        ->getJson(appFail2banUrl())
        ->assertOk()
        ->assertJsonPath('fail2ban', null)
        ->assertJsonStructure(['fail2ban', 'jail_template', 'filter_template']);
});

it('saves INI, tests it, and applies the configuration on success', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');

    $writes = [];
    fakeAppFail2ban(writes: $writes);
    // Make the closure-side write array visible to the test scope: PHP
    // closures capture by value, so the outer $writes is still empty until
    // we look at the same in-memory array through this alias.

    $jail = "[sVoss-shop]\nenabled  = true\nfilter   = sVoss-shop\nlogpath  = /tmp/log\nmaxretry = 5\n";
    $filter = "[sVoss-shop]\nfailregex = ^<HOST> .* \"(POST|PUT|DELETE) .*wp-login.php\nignoreregex =\n";

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => $jail,
            'filter_config_content' => $filter,
        ])
        ->assertOk()
        ->assertJsonPath('testOk', true);

    $application = $this->application->fresh();
    expect($application->fail2ban_jail_name)->toBe('sVoss-shop')
        ->and($application->fail2ban_jail_content)->toContain('maxretry = 5')
        ->and($application->fail2ban_filter_content)->toContain('failregex');

    // The jail and filter files were actually written to disk via tee.
    expect($writes)->toHaveKey($this->jailD.'/sVoss-shop.conf')
        ->and($writes)->toHaveKey($this->filterD.'/sVoss-shop.conf');

    // The manager replaces {name}/{filter}/{logpath}/{slug} placeholders, so
    // the on-disk file must reference the resolved logpath, not the literal
    // placeholder string.
    expect($writes[$this->jailD.'/sVoss-shop.conf'])->toContain('logpath  = ')
        ->not->toContain('{logpath}');
});

it('refuses to save when fail2ban-client -t reports a bad configuration', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');

    fakeAppFail2ban(testOk: false);

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => '[broken',
            'filter_config_content' => '[broken',
        ])
        ->assertStatus(500)
        ->assertJsonPath('testOk', false)
        ->assertJsonStructure(['testOk', 'message', 'output']);

    // Nothing was persisted: the controller must not save what it could not
    // validate, otherwise a 500 leaves the database ahead of the daemon.
    expect($this->application->fresh()->fail2ban_jail_content)->toBeNull();
});

it('disables fail2ban and clears the stored content', function () {
    $this->application = createFail2banApp('Shop', 'shop.test', 'php', [
        'fail2ban_jail_name' => 'sVoss-shop',
        'fail2ban_jail_content' => "[sVoss-shop]\nenabled  = true\n",
        'fail2ban_filter_content' => "[sVoss-shop]\nfailregex = ^<HOST>\n",
    ]);

    $jailFile = $this->jailD.'/sVoss-shop.conf';
    file_put_contents($jailFile, "[sVoss-shop]\nenabled  = true\n");

    fakeAppFail2ban();

    $this->withHeaders(appFail2banHeaders())
        ->deleteJson(appFail2banUrl())
        ->assertOk();

    $application = $this->application->fresh();
    expect($application->fail2ban_jail_name)->toBeNull()
        ->and($application->fail2ban_jail_content)->toBeNull()
        ->and($application->fail2ban_filter_content)->toBeNull();

    expect(file_exists($jailFile))->toBeFalse('jail file removed from disk');
});

it('reports already disabled when DELETE is called on a never-configured app', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');

    fakeAppFail2ban();

    $this->withHeaders(appFail2banHeaders())
        ->deleteJson(appFail2banUrl())
        ->assertStatus(500)
        ->assertJsonPath('message', 'Fail2ban is already disabled for this application.');
});

it('auto-migrates structured columns to raw INI on first GET', function () {
    $this->application = createFail2banApp('Blog', 'blog.test');

    // Simulate a row from the previous structured schema: the columns are
    // still on the table because the drop migration has not run yet.
    if (! Schema::hasColumn('applications', 'fail2ban_maxretry')) {
        Schema::table('applications', function ($table): void {
            $table->unsignedInteger('fail2ban_maxretry')->nullable()->after('fail2ban_enabled');
            $table->unsignedInteger('fail2ban_findtime')->nullable()->after('fail2ban_maxretry');
            $table->unsignedInteger('fail2ban_bantime')->nullable()->after('fail2ban_findtime');
            $table->json('fail2ban_ignore_ips')->nullable()->after('fail2ban_bantime');
        });
    }

    DB::table('applications')->where('id', $this->application->id)->update([
        'fail2ban_maxretry' => 7,
        'fail2ban_findtime' => 900,
        'fail2ban_bantime' => 7200,
        'fail2ban_ignore_ips' => json_encode(['127.0.0.1']),
    ]);

    fakeAppFail2ban();

    $this->withHeaders(appFail2banHeaders())
        ->getJson(appFail2banUrl())
        ->assertOk()
        ->assertJsonPath('fail2ban.jail_name', 'sVoss-blog');

    $fresh = $this->application->fresh();

    expect($fresh->fail2ban_jail_content)->toContain('maxretry = 7')
        ->and($fresh->fail2ban_jail_content)->toContain('bantime  = 7200')
        ->and($fresh->fail2ban_jail_content)->toContain('findtime = 900')
        ->and($fresh->fail2ban_filter_content)->toContain('failregex');

    // The old columns are cleared (will be dropped by the migration).
    $attributes = $fresh->getAttributes();
    expect($attributes['fail2ban_maxretry'] ?? null)->toBeNull()
        ->and($attributes['fail2ban_findtime'] ?? null)->toBeNull()
        ->and($attributes['fail2ban_bantime'] ?? null)->toBeNull();
});

it('refuses POST without manage permission', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');

    fakeAppFail2ban();

    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_fail2ban', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => '[x]',
            'filter_config_content' => '[x]',
        ])
        ->assertStatus(403);
});
