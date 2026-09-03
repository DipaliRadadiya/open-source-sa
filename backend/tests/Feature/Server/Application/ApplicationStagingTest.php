<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Staging: a staging site is just another `Application` row
 * (`production_application_id` set), provisioned through the normal path
 * and handed off to the WordPress-only `StagingStrategy` for the database
 * half. What matters here: one staging site per production app, only
 * WordPress offers it, a full push dumps a local safety copy before
 * touching production's database, and files-only push never does.
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

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->production = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.test',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);

    $database = Database::create(['name' => 'shop_db', 'engine' => 'mysql', 'application_id' => $this->production->id]);
    DatabaseUser::create(['database_id' => $database->id, 'username' => 'shop_user', 'password' => 'secret', 'connection_preference' => 'localhost', 'host' => 'localhost']);
});

function stagingHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

function fakeStagingServer(bool $testPasses = true): void
{
    Process::fake(function ($process) use ($testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true)
            && str_contains((string) $process->input, 'information_schema.schemata')) {
            return Process::result(output: '1');
        }

        return Process::result(exitCode: 0);
    });
}

function stagingUrl(): string
{
    return '/api/applications/'.test()->production->id.'/staging';
}

it('creates a staging site with its own database, linked back to production', function () {
    fakeStagingServer();
    $this->production->update(['name' => 'My Extremely Long Online Shop Application Name']);

    $response = $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])
        ->assertCreated()
        ->assertJsonPath('staging.is_staging', true)
        ->assertJsonPath('staging.domain', 'staging.shop.test');

    $stagingId = $response->json('staging.id');
    $staging = Application::find($stagingId);

    expect($staging)->not->toBeNull()
        ->and($staging->production_application_id)->toBe($this->production->id)
        ->and($staging->site_type)->toBe('wordpress')
        ->and($staging->status->value)->toBe('active')
        // Without a slug the document root collapses to the system user's
        // home, which production's own clone would land in too. `slug` is not
        // fillable, so mass assignment used to drop it here in silence.
        ->and($staging->slug)->not->toBeNull()
        ->and($staging->slug)->not->toBe($this->production->slug)
        ->and(app(ApplicationProvisioner::class)
            ->documentRoot($staging->load('systemUser')))
        ->toBe("/home/siteowner/{$staging->slug}/public_html")
        ->and(ActivityLog::where('type', 'application')->where('action', 'staging_created')->exists())->toBeTrue();

    $stagingDatabase = Database::where('application_id', $staging->id)->with('users')->first();

    expect($stagingDatabase)->not->toBeNull()
        ->and($stagingDatabase->name)->toStartWith('staging_')
        ->and(strlen($stagingDatabase->name))->toBeLessThanOrEqual(32)
        ->and($stagingDatabase->name)->toMatch('/_[a-z0-9]{6}$/')
        ->and($stagingDatabase->users->first()->username)->toBe($stagingDatabase->name);

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'rsync');
});

it('refuses a second staging site for the same application', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl(), ['domain' => 'staging2.shop.test'])
        ->assertStatus(500);

    expect(Application::where('production_application_id', $this->production->id)->count())->toBe(1);
});

it('refuses staging for a site type with no staging recipe', function () {
    fakeStagingServer();
    $static = Application::forceCreate([
        'system_user_id' => $this->production->system_user_id,
        'name' => 'Landing',
        'slug' => 'landing', 'domain' => 'landing.test', 'site_type' => 'static',
        'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
    ]);

    // 404, not 403/500: the `permission:app_` middleware already refuses any
    // app_-prefixed route for a site type that doesn't declare the feature
    // (CheckPermission.php) — the same "this screen does not exist here"
    // guard every other per-app feature already gets, before StagingManager
    // ever runs.
    $this->withHeaders(stagingHeaders())
        ->postJson('/api/applications/'.$static->id.'/staging', ['domain' => 'staging.landing.test'])
        ->assertStatus(404);
});

it('shows no staging site before one is created', function () {
    $this->withHeaders(stagingHeaders())
        ->getJson(stagingUrl())
        ->assertOk()
        ->assertJsonPath('staging', null);
});

it('pushes files-only without dumping the production database', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $dumps = [];
    Process::fake(function ($process) use (&$dumps) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'mysqldump') {
            $dumps[] = $args;
        }

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'files'])
        ->assertOk();

    expect($dumps)->toBeEmpty()
        ->and(ActivityLog::where('action', 'staging_pushed')->latest()->first()->properties['mode'] ?? null)->toBe('files');

    expect($this->production->fresh()->disabled_at)->toBeNull();
});

it('dumps a local safety copy before a full push', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $dumps = [];
    Process::fake(function ($process) use (&$dumps) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'mysqldump') {
            $dumps[] = $args;
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'full'])
        ->assertOk();

    // One dump for the safety copy, one for the staging->production push.
    expect(count($dumps))->toBeGreaterThanOrEqual(2)
        ->and($this->production->fresh()->disabled_at)->toBeNull(); // re-enabled after the push
});

it('restores production files before re-enabling when a push fails partway', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $rsyncCalls = [];
    $snapshotRemoved = false;

    Process::fake(function ($process) use (&$rsyncCalls, &$snapshotRemoved) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'rsync') {
            $rsyncCalls[] = $args;

            // Snapshot succeeds, staging -> production fails, snapshot ->
            // production succeeds. The site may only come back after #3.
            if (count($rsyncCalls) === 2) {
                return Process::result(exitCode: 1, errorOutput: 'push rsync failed');
            }
        }

        if (($args[0] ?? '') === 'rm' && ($args[1] ?? '') === '-rf'
            && str_contains((string) ($args[2] ?? ''), '/staging-rollbacks/')) {
            $snapshotRemoved = true;
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'files'])
        ->assertStatus(500);

    expect($rsyncCalls)->toHaveCount(3)
        ->and(implode(' ', $rsyncCalls[0]))->toContain('/staging-rollbacks/')
        ->and(implode(' ', $rsyncCalls[2]))->toContain('/staging-rollbacks/')
        ->and($snapshotRemoved)->toBeTrue()
        ->and($this->production->fresh()->disabled_at)->toBeNull();
});

it('restores the production database as well as files after a full push fails', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $rsyncCalls = [];
    $databaseRestores = [];

    Process::fake(function ($process) use (&$rsyncCalls, &$databaseRestores) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'rsync') {
            $rsyncCalls[] = $args;
        }

        if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true) && in_array('-e', $args, true)) {
            $databaseRestores[] = (string) ($args[array_search('-e', $args, true) + 1] ?? '');
        }

        // Fail after staging's database has already replaced production.
        if (($args[0] ?? '') === 'runuser' && in_array('search-replace', $args, true)) {
            return Process::result(exitCode: 1, errorOutput: 'url rewrite failed');
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'full'])
        ->assertStatus(500);

    expect($rsyncCalls)->toHaveCount(3)
        ->and($databaseRestores)->toHaveCount(2)
        ->and($databaseRestores[1])->toContain('/staging-backups/pre-push-')
        ->and($this->production->fresh()->disabled_at)->toBeNull();
});

it('leaves production disabled and preserves recovery files when rollback fails', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $rsyncCount = 0;
    $snapshotRemoved = false;

    Process::fake(function ($process) use (&$rsyncCount, &$snapshotRemoved) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'rsync') {
            $rsyncCount++;

            if ($rsyncCount >= 2) {
                return Process::result(exitCode: 1, errorOutput: $rsyncCount === 2 ? 'push failed' : 'restore failed');
            }
        }

        if (($args[0] ?? '') === 'rm' && ($args[1] ?? '') === '-rf'
            && str_contains((string) ($args[2] ?? ''), '/staging-rollbacks/')) {
            $snapshotRemoved = true;
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'files'])
        ->assertStatus(500)
        ->assertJsonPath('code', 'staging_rollback_failed')
        ->assertJsonPath('message', __('errors/application.staging_rollback_failed'));

    expect($rsyncCount)->toBe(3)
        ->and($snapshotRemoved)->toBeFalse()
        ->and($this->production->fresh()->disabled_at)->not->toBeNull();
});

it('never runs the marketplace installer against the copied files', function () {
    // Staging copies production's files first, then runs its own DB setup.
    // Running the installer would call `wp core install` against a site that
    // already has a database and URL configured — the same failure CloneManager
    // already avoids with `skipInstaller: true`.
    $installCalls = [];
    Process::fake(function ($process) use (&$installCalls) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        // Track any WordPress CLI call during provisioning.
        if (($args[0] ?? '') === 'wp' && ($args[1] ?? '') === 'core') {
            $installCalls[] = $args;
        }

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: 0);
        }

        if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true)
            && str_contains((string) $process->input, 'information_schema.schemata')) {
            return Process::result(output: '1');
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])
        ->assertCreated();

    // The installer must never have been asked to run `wp core install`.
    expect($installCalls)->toBeEmpty();
});

it('refuses without manage permission', function () {
    fakeStagingServer();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_staging', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])
        ->assertStatus(403);
});

/*
 * The failure path, which had no test at all — every existing one fakes a
 * server where nothing goes wrong, so "what happens when it does" was never
 * asked. A staging attempt creates its Application row before it provisions,
 * and that row satisfies the one-staging-per-site guard. Left behind, it
 * meant a single failure locked staging off for that site permanently.
 */
describe('when creating staging fails', function () {
    it('leaves nothing behind and lets the user try again', function () {
        fakeStagingServer(testPasses: false);

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])
            ->assertStatus(500);

        // No half-made site in the list, and nothing holding the domain.
        expect(Application::where('production_application_id', $this->production->id)->exists())
            ->toBeFalse()
            ->and(Application::where('domain', 'staging.shop.test')->exists())->toBeFalse();

        // The whole point: the same request now works.
        fakeStagingServer();

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])
            ->assertCreated();

        expect(Application::where('production_application_id', $this->production->id)->count())->toBe(1);
    });

    it('still reports why it failed', function () {
        fakeStagingServer(testPasses: false);

        // Cleanup must not swallow the reason. The reference is what ties the
        // failure to the `server-ops` log line holding the actual nginx error,
        // and it travels inside the message rather than as its own key.
        $message = $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])
            ->assertStatus(500)
            ->json('message');

        expect($message)->toContain('test_config')
            ->and($message)->toMatch('/reference [0-9a-f-]{36}/');
    });

    it('does not activate staging when copied files cannot be owned by the site user', function () {
        $filesCopied = false;

        Process::fake(function ($process) use (&$filesCopied) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
                return Process::result(exitCode: 0);
            }

            if (($args[0] ?? '') === 'rsync') {
                $filesCopied = true;

                return Process::result(exitCode: 0);
            }

            if ($filesCopied && ($args[0] ?? '') === 'chown' && ($args[1] ?? '') === '-R') {
                return Process::result(exitCode: 1, errorOutput: 'ownership denied');
            }

            if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true)
                && str_contains((string) $process->input, 'information_schema.schemata')) {
                return Process::result(output: '1');
            }

            return Process::result(exitCode: 0);
        });

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])
            ->assertStatus(500);

        expect(Application::where('production_application_id', $this->production->id)->exists())->toBeFalse();
    });

    it('does not keep staging when its secret config cannot be secured', function (string $command) {
        Process::fake(function ($process) use ($command) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
                return Process::result(exitCode: 0);
            }

            if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true)
                && str_contains((string) $process->input, 'information_schema.schemata')) {
                return Process::result(output: '1');
            }

            if (($args[0] ?? '') === $command && str_ends_with((string) end($args), '/wp-config.php')) {
                return Process::result(exitCode: 1, errorOutput: 'permission denied');
            }

            return Process::result(exitCode: 0);
        });

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])
            ->assertStatus(500);

        expect(Application::where('production_application_id', $this->production->id)->exists())->toBeFalse();
    })->with(['chmod', 'chown']);
});

/*
 * What a push must never carry from staging to production.
 *
 * Reported from a live site: after pushing, production was serving the
 * staging URL. The cause was not the URL rewrite — that ran and worked. The
 * rsync had no exclusion for `wp-config.php`, so staging's config landed on
 * production carrying staging's DB_NAME/DB_USER/DB_PASSWORD and its
 * WP_HOME/WP_SITEURL. Those are PHP constants, so they override whatever
 * `wp_options` says: the live site connected to the staging database and
 * answered on the staging URL, and the search-replace was overruled in
 * silence. The mail trap rode across in the same sync, which stops a live
 * site sending email at all.
 */

/** Records every command a push runs, so the sync and the wp-cli calls can be read back. */
function recordStagingPush(array &$commands): void
{
    Process::fake(function ($process) use (&$commands) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $commands[] = $args;

        // The verification step reads production's wp-config; answer with a
        // file that still names production's own database.
        if (($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), 'wp-config.php')) {
            return Process::result(output: "<?php\ndefine('DB_NAME', 'shop_db');\ndefine('WP_HOME', 'https://shop.test');\n");
        }

        return Process::result(exitCode: 0);
    });
}

/**
 * The rsync that pushes staging onto production.
 *
 * Not simply the first one: a push starts by rsyncing production's own files
 * into the rollback snapshot directory, and that copy is deliberately
 * unfiltered — a safety copy with exclusions would not restore the site. An
 * earlier version of this helper matched that one and reported the push as
 * having no exclusions at all.
 */
function pushRsyncArgs(array $commands): array
{
    foreach ($commands as $args) {
        if (($args[0] ?? '') === 'rsync' && str_ends_with((string) end($args), '/public_html/')) {
            return $args;
        }
    }

    return [];
}

/** Every wp-cli invocation, flattened — they run behind `runuser -u <user> --`. */
function wpCommands(array $commands): array
{
    return collect($commands)
        ->filter(fn (array $args) => collect($args)->contains(fn ($a) => str_ends_with((string) $a, '/wp')))
        ->map(fn (array $args) => implode(' ', $args))
        ->values()
        ->all();
}

/** The search terms passed to `wp search-replace`, in order. */
function searchReplaceTerms(array $commands): array
{
    $terms = [];

    foreach ($commands as $args) {
        $index = array_search('search-replace', $args, true);

        if ($index !== false) {
            $terms[] = (string) ($args[$index + 1] ?? '');
        }
    }

    return $terms;
}

it('never copies wp-config.php onto production', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertOk();

    $rsync = pushRsyncArgs($commands);

    expect($rsync)->not->toBeEmpty()
        // The single most important exclusion in this feature: it carries the
        // database credentials and the site's own URL.
        ->and($rsync)->toContain('wp-config.php');
});

it('never copies the staging mail trap onto production', function () {
    // Pushed to production this makes wp_mail() return without sending, so
    // the live site stops delivering order confirmations and password resets
    // with no error anywhere.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertOk();

    expect(pushRsyncArgs($commands))->toContain('wp-content/mu-plugins/panel-staging-mail-trap.php');
});

it('rewrites every spelling of the staging URL, not just one', function () {
    // A single literal replace misses the cases that leave a site half
    // migrated: a scheme mismatch between the two sites, the escaped slashes
    // the block editor stores, protocol-relative asset URLs, and the bare
    // host in email templates and plugin settings.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertOk();

    $searches = searchReplaceTerms($commands);

    expect($searches)->toContain('https://staging.shop.test')
        ->and($searches)->toContain('http://staging.shop.test')
        ->and($searches)->toContain('//staging.shop.test')
        ->and($searches)->toContain('staging.shop.test')
        // The escaped form: JSON-encoded options and block-editor content.
        ->and(collect($searches)->contains(fn (string $s) => str_contains($s, '\\/\\/staging.shop.test')))->toBeTrue();
});

it('flushes caches and rewrite rules after a full push', function () {
    // The database was just replaced: a stale object cache serves the old
    // site, and rewrite rules from staging 404 every post and page until
    // somebody re-saves permalinks.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertOk();

    $wpSubcommands = wpCommands($commands);

    expect(collect($wpSubcommands)->contains(fn (string $c) => str_contains($c, 'cache flush')))->toBeTrue()
        ->and(collect($wpSubcommands)->contains(fn (string $c) => str_contains($c, 'transient delete')))->toBeTrue()
        ->and(collect($wpSubcommands)->contains(fn (string $c) => str_contains($c, 'rewrite flush')))->toBeTrue();
});

it('flushes caches on a files-only push too', function () {
    // Templates and assets changed; a page cache serving the old markup makes
    // the push look like it did nothing.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])->assertOk();

    expect(collect($commands)->contains(fn (array $args) => in_array('cache', $args, true) && in_array('flush', $args, true)))->toBeTrue();
});

it('refuses to finish a push that left production wearing staging identity', function () {
    // The catch-all: whatever else changes about the sync, a live site that
    // came out of a push pointed at the staging database must not be
    // reported as a success.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $staging = Application::where('production_application_id', $this->production->id)->first();
    $stagingDatabase = Database::where('application_id', $staging->id)->first();

    Process::fake(function ($process) use ($stagingDatabase) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        // Production's wp-config comes back naming the *staging* database.
        if (($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), 'wp-config.php')) {
            return Process::result(output: "<?php\ndefine('DB_NAME', '{$stagingDatabase->name}');\n");
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'full'])
        ->assertStatus(500);
});
