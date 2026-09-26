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
/**
 * fail2ban as the panel meets it on a real server.
 *
 * `-t` with no `-c` tests the LIVE tree, which is valid — so it passes
 * whatever was submitted. That is exactly what the old testConfigs() ran, and
 * why `[broken` reached /etc/fail2ban on a real server (2026-09-23): the fake
 * here answered `-t` without asking which config it was pointed at, so the
 * suite passed the broken code too. Now `-c <stage>` is judged by what was
 * written into that stage, and anything containing `[broken` fails as the
 * real client does (exit 255).
 *
 * @param  array<string, string>  $writes  path => content, every `tee`
 */
function fakeAppFail2ban(
    bool $testOk = true,
    array &$writes = [],
    bool $reloadOk = true,
    bool $existing = true,
    ?ArrayObject $runs = null,
    array $packageOwned = [],
): void {
    Process::fake(function ($process) use ($testOk, &$writes, $reloadOk, $existing, $runs, $packageOwned) {
        $args = $process->command[0] === 'sudo'
            ? array_slice($process->command, 2)
            : $process->command;

        $runs?->append($args);

        if (($args[0] ?? '') === 'tee') {
            $writes[$args[1] ?? ''] = (string) $process->input;

            return Process::result(exitCode: 0);
        }

        // Whether a jail/filter is already on disk, for the backup step.
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-f') {
            return Process::result(exitCode: $existing ? 0 : 1);
        }

        // `rm -f <path>` is what disableForApp() runs. Process::fake does
        // not actually delete the file, so the disable test would never see
        // the file leave disk without us mirroring the call here.
        if (($args[0] ?? '') === 'rm' && in_array(($args[1] ?? ''), ['-f', '--force'], true)) {
            foreach (array_slice($args, 2) as $target) {
                if (file_exists($target)) {
                    @unlink($target);
                }
            }

            return Process::result(exitCode: 0);
        }

        // `dpkg-query -S <path>`, answered the way the real one does: 0 when
        // the package owns the file, otherwise exit 1 *and* a line on stderr.
        // An earlier fake answered "not owned" silently, and passed code that
        // read every real "not owned" as "could not find out".
        if (($args[0] ?? '') === 'dpkg-query') {
            return in_array($args[2] ?? '', $packageOwned, true)
                ? Process::result(output: 'fail2ban: '.($args[2] ?? '')."\n")
                : Process::result(errorOutput: 'dpkg-query: no path found matching pattern '.($args[2] ?? '')."\n", exitCode: 1);
        }

        if (($args[0] ?? '') === 'fail2ban-client') {
            if (in_array('-t', $args, true)) {
                $c = array_search('-c', $args, true);

                // No -c: the live tree, which is fine — so it passes.
                if ($c === false) {
                    return Process::result(output: "OK: configuration test is successful\n");
                }

                $stage = $args[$c + 1] ?? '';
                $staged = array_filter($writes, fn (string $content, string $path) => str_starts_with($path, $stage.'/'), ARRAY_FILTER_USE_BOTH);
                $broken = ! $testOk || collect($staged)->contains(fn (string $content) => str_contains($content, '[broken'));

                return $broken
                    ? Process::result(errorOutput: "ERROR: test configuration failed\n", exitCode: 255)
                    : Process::result(output: "OK: configuration test is successful\n");
            }

            return match ($args[1] ?? '') {
                'ping' => Process::result(output: 'Server replied: pong'),
                'reload' => Process::result(exitCode: $reloadOk ? 0 : 255),
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
        ->and($jail)->toContain('[panel-site-shop]')
        ->and($jail)->toContain('filter   = panel-site-shop')
        // The site's own log directory — fail2ban follows `logPaths()`, so
        // moving the logs moved the jail with them for free.
        ->and($jail)->toContain('/logs/access.log')
        ->and($filter)->not->toContain('{name}');
});

it('states the backend, so the jail reads its log file whatever [DEFAULT] says', function () {
    // A jail that names a `logpath` and inherits its backend is one edit away
    // from reading the journal instead — and that is not hypothetical: this
    // panel shipped `backend = systemd` in `[DEFAULT]` of jail.local, which
    // applies to every jail, so every jail generated here watched a file
    // fail2ban never opened. It matched nothing and banned nobody while the
    // panel reported it enabled — the same symptom as the `[Definition]` bug
    // below, from a completely different cause.
    //
    // Proven on a live server both ways: as shipped the jail reported
    // `Total failed: 0` against a log full of matching requests; with the
    // backend stated it reported `File list: …/access.log`, four failures and
    // a ban.
    $this->application = createFail2banApp('Shop', 'shop.test');

    $jail = $this->withHeaders(appFail2banHeaders())
        ->getJson(appFail2banUrl())
        ->assertOk()
        ->json('jail_template');

    expect($jail)->toContain('backend  = auto');
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

    // The site's own name, spelled out rather than as `{name}`, is accepted.
    $jail = "[panel-site-shop]\nenabled  = true\nfilter   = panel-site-shop\nlogpath  = /tmp/log\nmaxretry = 5\n";
    $filter = "[Definition]\nfailregex = ^<HOST> .* \"(POST|PUT|DELETE) .*wp-login.php\nignoreregex =\n";

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => $jail,
            'filter_config_content' => $filter,
        ])
        ->assertOk()
        ->assertJsonPath('testOk', true);

    // Changes what gets banned on a live site — it has to be on the record.
    $this->assertDatabaseHas('activity_logs', ['type' => 'application', 'action' => 'fail2ban_enabled', 'subject_id' => $this->application->id]);

    $application = $this->application->fresh();
    expect($application->fail2ban_jail_name)->toBe('panel-site-shop')
        ->and($application->fail2ban_jail_content)->toContain('maxretry = 5')
        ->and($application->fail2ban_filter_content)->toContain('failregex');

    // The jail and filter files were actually written to disk via tee.
    expect($writes)->toHaveKey($this->jailD.'/panel-site-shop.conf')
        ->and($writes)->toHaveKey($this->filterD.'/panel-site-shop.conf');

    // The manager replaces {name}/{filter}/{logpath}/{slug} placeholders, so
    // the on-disk file must reference the resolved logpath, not the literal
    // placeholder string.
    expect($writes[$this->jailD.'/panel-site-shop.conf'])->toContain('logpath  = ')
        ->not->toContain('{logpath}');
});

/*
 * The incident itself (2026-09-23, real server): `[broken` passed the test,
 * was written over the live jail and filter, and only the reload failed.
 * No `testOk: false` here — the fake judges what was staged, as the real
 * client does, so this fails if the test ever stops pointing at the stage.
 */
it('refuses a broken config because it tests the config it was given', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');
    $writes = [];
    $runs = new ArrayObject;
    fakeAppFail2ban(writes: $writes, runs: $runs);

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), ['jail_config_content' => '[broken', 'filter_config_content' => '[broken'])
        ->assertStatus(422)
        ->assertJsonPath('testOk', false);

    $commands = collect($runs->getArrayCopy());
    $test = $commands->first(fn (array $c) => ($c[0] ?? '') === 'fail2ban-client' && in_array('-t', $c, true));
    $stage = $test[array_search('-c', $test, true) + 1];

    // The whole live tree copied into the stage, and the stage removed.
    expect($commands)->toContain(['cp', '-a', dirname($this->jailD).'/.', $stage.'/'])
        ->toContain(['rm', '-rf', $stage]);

    // Nothing reached the live files, nothing was reloaded, nothing saved.
    expect(collect(array_keys($writes))->filter(fn (string $p) => ! str_starts_with($p, $stage.'/')))->toBeEmpty()
        ->and($commands->contains(fn (array $c) => ($c[1] ?? '') === 'reload'))->toBeFalse()
        ->and($this->application->fresh()->fail2ban_jail_content)->toBeNull();
});

it('puts the previous jail back and reloads again when the reload fails', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');
    $runs = new ArrayObject;
    fakeAppFail2ban(reloadOk: false, existing: true, runs: $runs);

    $jail = "[{name}]\nenabled = true\nfilter = {filter}\nlogpath = {logpath}\n";

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), ['jail_config_content' => $jail, 'filter_config_content' => "[Definition]\nfailregex = ^<HOST>\n"])
        ->assertStatus(500);

    $commands = collect($runs->getArrayCopy());
    $jailPath = $this->jailD.'/panel-site-shop.conf';

    expect($commands)->toContain(['cp', '-p', $jailPath, $jailPath.'.panel-bak'])
        ->toContain(['mv', '-f', $jailPath.'.panel-bak', $jailPath])
        ->and($commands->filter(fn (array $c) => ($c[1] ?? '') === 'reload'))->toHaveCount(2)
        // Applied first, recorded after: a rolled-back config is not saved.
        ->and($this->application->fresh()->fail2ban_jail_content)->toBeNull();
});

it('removes a first-time jail again when its reload fails', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');
    $runs = new ArrayObject;
    fakeAppFail2ban(reloadOk: false, existing: false, runs: $runs);

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), ['jail_config_content' => "[{name}]\nenabled = true\n", 'filter_config_content' => "[Definition]\nfailregex = ^<HOST>\n"])
        ->assertStatus(500);

    expect(collect($runs->getArrayCopy()))->toContain(['rm', '-f', $this->jailD.'/panel-site-shop.conf']);
});

it('drops the backup once the new jail is live', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');
    $runs = new ArrayObject;
    fakeAppFail2ban(existing: true, runs: $runs);

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), ['jail_config_content' => "[{name}]\nenabled = true\n", 'filter_config_content' => "[Definition]\nfailregex = ^<HOST>\n"])
        ->assertOk();

    expect(collect($runs->getArrayCopy()))->toContain(['rm', '-f', $this->jailD.'/panel-site-shop.conf.panel-bak'])
        ->and($this->application->fresh()->fail2ban_jail_content)->toContain('enabled = true');
});

it('refuses to save when fail2ban-client -t reports a bad configuration', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');

    fakeAppFail2ban(testOk: false);

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => '[broken',
            'filter_config_content' => '[broken',
        ])
        // A refused config is the user's to fix — 422, not a server error.
        ->assertStatus(422)
        ->assertJsonPath('testOk', false)
        ->assertJsonStructure(['testOk', 'message', 'output']);

    // Nothing was persisted: the controller must not save what it could not
    // validate, otherwise a refusal leaves the database ahead of the daemon.
    expect($this->application->fresh()->fail2ban_jail_content)->toBeNull();
});

it('disables fail2ban and clears the stored content', function () {
    $this->application = createFail2banApp('Shop', 'shop.test', 'php', [
        'fail2ban_jail_name' => 'shop',
        'fail2ban_jail_content' => "[shop]\nenabled  = true\n",
        'fail2ban_filter_content' => "[shop]\nfailregex = ^<HOST>\n",
    ]);

    // Enabled before names were prefixed: its files are still `shop.conf`.
    $jailFile = $this->jailD.'/shop.conf';
    file_put_contents($jailFile, "[shop]\nenabled  = true\n");
    $filterFile = $this->filterD.'/shop.conf';
    file_put_contents($filterFile, "[Definition]\nfailregex = ^<HOST>\n");

    fakeAppFail2ban();

    $this->withHeaders(appFail2banHeaders())
        ->deleteJson(appFail2banUrl())
        ->assertOk();

    $application = $this->application->fresh();
    expect($application->fail2ban_jail_name)->toBeNull()
        ->and($application->fail2ban_jail_content)->toBeNull()
        ->and($application->fail2ban_filter_content)->toBeNull();

    expect(file_exists($jailFile))->toBeFalse('jail file removed from disk')
        // #20: the filter is the site's own too, and a left-behind one is what
        // made a name collision permanent.
        ->and(file_exists($filterFile))->toBeFalse('filter file removed from disk');

    $this->assertDatabaseHas('activity_logs', ['type' => 'application', 'action' => 'fail2ban_disabled', 'subject_id' => $this->application->id]);
});

it('reports already disabled when DELETE is called on a never-configured app', function () {
    $this->application = createFail2banApp('Shop', 'shop.test');

    fakeAppFail2ban();

    $this->withHeaders(appFail2banHeaders())
        ->deleteJson(appFail2banUrl())
        ->assertStatus(422)
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

    // The log path is left as the placeholder rather than resolved here, so
    // the write path substitutes whatever the active web-server driver says.
    // It used to be baked in as `/var/log/nginx/{slug}.access.log`: on an
    // OpenLiteSpeed server — which logs inside the site's own directory — the
    // migrated jail watched a file that does not exist, and banned nobody
    // while reporting itself enabled.
    expect($fresh->fail2ban_jail_content)
        ->toContain('logpath  = {logpath}')
        ->not->toContain('/var/log/nginx/');

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
            'jail_config_content' => "[{name}]\nenabled  = true\nfilter   = {filter}\nlogpath  = /tmp/log\nmaxretry = 5\n",
            'filter_config_content' => "[Definition]\nfailregex = ^<HOST> .*\nignoreregex =\n",
        ])->assertOk();

    // After: the jail exists, so the card must say on. This is the assertion
    // that was missing — the screen below was already right.
    $after = $this->withHeaders(appFail2banHeaders())
        ->getJson('/api/applications/'.$this->application->id)->assertOk();

    expect($after->json('application.fail2ban_enabled'))->toBeTrue()
        ->and($this->withHeaders(appFail2banHeaders())->getJson(appFail2banUrl())->json('fail2ban.jail_name'))
        ->toBe('panel-site-nextcloud');
});

it('goes back to off for both screens when the jail is removed', function () {
    $this->application = createFail2banApp('Nextcloud', 'cloud.test');

    fakeAppFail2ban();

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => "[{name}]\nenabled  = true\nfilter   = {filter}\nlogpath  = /tmp/log\nmaxretry = 5\n",
            'filter_config_content' => "[Definition]\nfailregex = ^<HOST> .*\nignoreregex =\n",
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

/*
|--------------------------------------------------------------------------
| A site's jail can never be one of fail2ban's own
|--------------------------------------------------------------------------
|
| Names were the bare site slug, so a site called `sshd` overwrote
| /etc/fail2ban/filter.d/sshd.conf with a WordPress regex and replaced the
| server's [sshd] jail — reproduced on a real server, SSH protection gone
| while the panel said "configured successfully".
*/

it('writes a site called sshd under its own prefixed name, never over fail2ban\'s', function () {
    $this->application = createFail2banApp('sshd', 'sshd.test');
    $writes = [];
    fakeAppFail2ban(writes: $writes);

    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => "[{name}]\nenabled = true\nfilter = {filter}\nlogpath = {logpath}\n",
            'filter_config_content' => "[Definition]\nfailregex = ^<HOST>\n",
        ])
        ->assertOk();

    $live = collect(array_keys($writes))->reject(fn (string $path) => str_contains($path, 'panel-f2b-test-'));

    expect($live->values()->all())->toEqualCanonicalizing([
        $this->jailD.'/panel-site-sshd.conf',
        $this->filterD.'/panel-site-sshd.conf',
    ])
        ->and($writes[$this->jailD.'/panel-site-sshd.conf'])->toContain('[panel-site-sshd]')
        ->and($writes[$this->jailD.'/panel-site-sshd.conf'])->not->toContain('[sshd]');
});

it('refuses custom config that names a jail or filter other than the site\'s own', function (string $jail) {
    $this->application = createFail2banApp('Shop', 'shop.test');
    $writes = [];
    fakeAppFail2ban(writes: $writes);

    // Valid fail2ban config — `-t` accepts it — and exactly how the server's
    // SSH jail (or, for [DEFAULT], every jail) gets replaced from a site screen.
    $this->withHeaders(appFail2banHeaders())
        ->postJson(appFail2banUrl(), [
            'jail_config_content' => $jail,
            'filter_config_content' => "[Definition]\nfailregex = ^<HOST>\n",
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('jail_config_content');

    expect($writes)->toBe([]);
})->with([
    'server ssh jail' => "[sshd]\nenabled = true\n",
    'every jail' => "[DEFAULT]\nbantime = 1\n[{name}]\nenabled = true\n",
    'fail2ban\'s filter' => "[{name}]\nenabled = true\nfilter = sshd\n",
    'filter with options' => "[{name}]\nenabled = true\nfilter = sshd[mode=aggressive]\n",
]);

it('moves a jail enabled under the bare slug on fail2ban:resync', function () {
    $this->application = createFail2banApp('Shop', 'shop.test', 'php', [
        'fail2ban_jail_name' => 'shop',
        // What the old structured form generated: the name spelled out.
        'fail2ban_jail_content' => "[shop]\nenabled  = true\nfilter   = shop\nlogpath  = {logpath}\n",
        'fail2ban_filter_content' => "[shop]\nfailregex = ^<HOST>\n",
    ]);
    file_put_contents($this->jailD.'/shop.conf', "[shop]\n");
    file_put_contents($this->filterD.'/shop.conf', "[shop]\n");

    $writes = [];
    fakeAppFail2ban(writes: $writes);

    $this->artisan('fail2ban:resync')->assertSuccessful();

    $application = $this->application->fresh();

    expect($application->fail2ban_jail_name)->toBe('panel-site-shop')
        ->and($application->fail2ban_jail_content)->toContain('[{name}]')
        ->and($application->fail2ban_jail_content)->toContain('filter   = {filter}')
        ->and($application->fail2ban_filter_content)->toContain('[Definition]')
        ->and($writes[$this->jailD.'/panel-site-shop.conf'])->toContain('[panel-site-shop]')
        ->and($writes[$this->jailD.'/panel-site-shop.conf'])->toContain('filter   = panel-site-shop')
        ->and(file_exists($this->jailD.'/shop.conf'))->toBeFalse()
        ->and(file_exists($this->filterD.'/shop.conf'))->toBeFalse();
});

it('keeps a filter the fail2ban package owns when moving a colliding jail, and says so', function () {
    $this->application = createFail2banApp('sshd', 'sshd.test', 'php', [
        'fail2ban_jail_name' => 'sshd',
        'fail2ban_jail_content' => "[{name}]\nenabled = true\nfilter = {filter}\nlogpath = {logpath}\n",
        'fail2ban_filter_content' => "[Definition]\nfailregex = ^<HOST>\n",
    ]);
    file_put_contents($this->jailD.'/sshd.conf', "[sshd]\n");
    file_put_contents($this->filterD.'/sshd.conf', "[Definition]\n");

    fakeAppFail2ban(packageOwned: [$this->filterD.'/sshd.conf']);

    // Deleting it would leave the server's [sshd] jail pointing at a filter
    // that is gone — and fail2ban would not start. Overwritten beats missing.
    $this->artisan('fail2ban:resync')
        ->expectsOutputToContain('belongs to the fail2ban package')
        ->assertSuccessful();

    expect(file_exists($this->filterD.'/sshd.conf'))->toBeTrue()
        ->and(file_exists($this->jailD.'/sshd.conf'))->toBeFalse()
        ->and($this->application->fresh()->fail2ban_jail_name)->toBe('panel-site-sshd');
});

it('treats a filter as package-owned when dpkg cannot be asked', function () {
    $this->application = createFail2banApp('Shop', 'shop.test', 'php', [
        'fail2ban_jail_name' => 'shop',
        'fail2ban_jail_content' => "[{name}]\nenabled = true\n",
        'fail2ban_filter_content' => "[Definition]\nfailregex = ^<HOST>\n",
    ]);
    file_put_contents($this->filterD.'/shop.conf', "[Definition]\n");

    fakeAppFail2ban();
    // sudo refusing dpkg-query: exit 1 with a different line. Unknown is
    // treated as owned — keeping a stray file costs nothing, deleting a real
    // one stops fail2ban from starting.
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'dpkg-query') {
            return Process::result(errorOutput: "sudo: a password is required\n", exitCode: 1);
        }

        if (($args[0] ?? '') === 'rm') {
            foreach (array_slice($args, 2) as $target) {
                @unlink($target);
            }
        }

        return Process::result(exitCode: 0);
    });

    $this->artisan('fail2ban:resync')->assertSuccessful();

    expect(file_exists($this->filterD.'/shop.conf'))->toBeTrue();
});
