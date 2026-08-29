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
    config([
        'server.fail2ban_apps.jail_d' => $this->jailD,
        'server.fail2ban_apps.filter_d' => $this->filterD,
    ]);
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
 * a slug the fail2ban jail name falls back to the domain (`shop.test`)
 * and the test assertions miss the cleaner `shop` form.
 */
function createFail2banApp(string $name, string $domain, string $siteType = 'php', array $extra = []): Application
{
    // forceCreate: `slug` is not in $fillable (it is server-derived) but the
    // test wants the resolved slug on the row so the jail name is shop,
    // not shop.test. CreateApplication uses forceCreate too for the same
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

it('hands the form a filled-in template, not one full of placeholders', function () {
    // This endpoint returned `defaultJailContent()` raw, so the form was
    // pre-filled with `[{name}]`, `filter = {filter}` and `logpath =
    // {logpath}` — and invited the user to save that. The write path
    // substitutes them, so the file on disk was right while the screen was
    // wrong: nothing the user read matched what the server had.
    //
    // The structure assertion above passed throughout, because a template
    // made entirely of placeholders is still a string under the right key.
    $this->application = createFail2banApp('Shop', 'shop.test');

    $response = $this->withHeaders(appFail2banHeaders())
        ->getJson(appFail2banUrl())
        ->assertOk();

    $jail = $response->json('jail_template');
    $filter = $response->json('filter_template');

    expect($jail)->not->toContain('{name}')
        ->and($jail)->not->toContain('{filter}')
        ->and($jail)->not->toContain('{logpath}')
        // The real values, so the user can see what will be written.
        ->and($jail)->toContain('[shop]')
        ->and($jail)->toContain('filter   = shop')
        // The site's own log directory — fail2ban follows `logPaths()`, so
        // moving the logs moved the jail with them for free.
        ->and($jail)->toContain('/logs/access.log')
        ->and($filter)->not->toContain('{name}');
});

it('generates a filter fail2ban can actually read', function () {
    // A fail2ban *filter* names its section `Definition`; only a *jail* is
    // named after itself. This emitted `[{name}]`, so the file had no
    // Definition section, fail2ban found no failregex, and the jail banned
    // nobody — while the panel reported it enabled.
    //
    // The two filters this repository already ships get it right; only the
    // generated default did not.
    $this->application = createFail2banApp('Shop', 'shop.test');

    $filter = $this->withHeaders(appFail2banHeaders())
        ->getJson(appFail2banUrl())
        ->assertOk()
        ->json('filter_template');

    expect($filter)->toContain('[Definition]')
        ->and($filter)->not->toContain('[shop]')
        ->and($filter)->toContain('failregex');
});

it('saves INI, tests it, and applies the configuration on success', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');

    $writes = [];
    fakeAppFail2ban(writes: $writes);
    // Make the closure-side write array visible to the test scope: PHP
    // closures capture by value, so the outer $writes is still empty until
    // we look at the same in-memory array through this alias.

    $jail = "[shop]\nenabled  = true\nfilter   = shop\nlogpath  = /tmp/log\nmaxretry = 5\n";
    $filter = "[shop]\nfailregex = ^<HOST> .* \"(POST|PUT|DELETE) .*wp-login.php\nignoreregex =\n";

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => $jail,
            'filter_config_content' => $filter,
        ])
        ->assertOk()
        ->assertJsonPath('testOk', true);

    $application = $this->application->fresh();
    expect($application->fail2ban_jail_name)->toBe('shop')
        ->and($application->fail2ban_jail_content)->toContain('maxretry = 5')
        ->and($application->fail2ban_filter_content)->toContain('failregex');

    // The jail and filter files were actually written to disk via tee.
    expect($writes)->toHaveKey($this->jailD.'/shop.conf')
        ->and($writes)->toHaveKey($this->filterD.'/shop.conf');

    // The manager replaces {name}/{filter}/{logpath}/{slug} placeholders, so
    // the on-disk file must reference the resolved logpath, not the literal
    // placeholder string.
    expect($writes[$this->jailD.'/shop.conf'])->toContain('logpath  = ')
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
        'fail2ban_jail_name' => 'shop',
        'fail2ban_jail_content' => "[shop]\nenabled  = true\n",
        'fail2ban_filter_content' => "[shop]\nfailregex = ^<HOST>\n",
    ]);

    $jailFile = $this->jailD.'/shop.conf';
    file_put_contents($jailFile, "[shop]\nenabled  = true\n");

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
        ->assertJsonPath('fail2ban.jail_name', 'blog');

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

/*
 * The dashboard card and this screen disagreed for as long as both existed.
 *
 * The card reads `fail2ban_enabled` off the application resource; this screen
 * reads the jail columns. The column had exactly one writer — an action nothing
 * called, which itself called a manager method that does not exist — so it was
 * `false` on every application ever created, and the card said "Off" for sites
 * with a jail running. Two representations of one fact, and the orphaned one
 * won wherever it was consulted.
 *
 * These assert the two answers together, in one test, because that is the only
 * shape that fails when they drift apart again.
 */
it('agrees with the application resource about whether fail2ban is on', function () {
    $this->application = createFail2banApp('Nextcloud', 'cloud.test');

    fakeAppFail2ban();

    // Before: no jail, and both screens say so.
    $before = $this->withHeaders(appFail2banHeaders())
        ->getJson('/api/applications/'.$this->application->id)->assertOk();

    expect($before->json('application.fail2ban_enabled'))->toBeFalse()
        ->and($this->withHeaders(appFail2banHeaders())->getJson(appFail2banUrl())->json('fail2ban'))->toBeNull();

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => "[nextcloud]\nenabled  = true\nfilter   = nextcloud\nlogpath  = /tmp/log\nmaxretry = 5\n",
            'filter_config_content' => "[nextcloud]\nfailregex = ^<HOST> .*\nignoreregex =\n",
        ])->assertOk();

    // After: the jail exists, so the card must say on. This is the assertion
    // that was missing — the screen below was already right.
    $after = $this->withHeaders(appFail2banHeaders())
        ->getJson('/api/applications/'.$this->application->id)->assertOk();

    expect($after->json('application.fail2ban_enabled'))->toBeTrue()
        ->and($this->withHeaders(appFail2banHeaders())->getJson(appFail2banUrl())->json('fail2ban.jail_name'))
        ->toBe('nextcloud');
});

it('goes back to off for both screens when the jail is removed', function () {
    $this->application = createFail2banApp('Nextcloud', 'cloud.test');

    fakeAppFail2ban();

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => "[nextcloud]\nenabled  = true\nfilter   = nextcloud\nlogpath  = /tmp/log\nmaxretry = 5\n",
            'filter_config_content' => "[nextcloud]\nfailregex = ^<HOST> .*\nignoreregex =\n",
        ])->assertOk();

    $this->withHeaders(appFail2banHeaders())->deleteJson(appFail2banUrl())->assertOk();

    // `destroy()` nulls the jail columns, so the derived flag follows without
    // anything having to remember to clear a second one.
    expect($this->withHeaders(appFail2banHeaders())
        ->getJson('/api/applications/'.$this->application->id)
        ->json('application.fail2ban_enabled'))->toBeFalse();
});

/*
 * The stored boolean is deliberately not consulted any more. Pinned because the
 * column still exists — dropping it needs a schema change, and pre-1.0 that
 * means migrate:fresh, which must never touch the shared dev database — so the
 * temptation to "use the field that is right there" outlives this fix.
 */
it('ignores the orphaned fail2ban_enabled column entirely', function () {
    $this->application = createFail2banApp('Docs', 'docs.test');

    // A stale true, of the kind no code path can produce today but a hand-edit
    // or an old row could: the answer still comes from the jail.
    $this->application->forceFill(['fail2ban_enabled' => true])->save();

    expect($this->withHeaders(appFail2banHeaders())
        ->getJson('/api/applications/'.$this->application->id)
        ->json('application.fail2ban_enabled'))->toBeFalse();
});
