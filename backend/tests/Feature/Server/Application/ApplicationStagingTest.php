<?php

use App\Enums\DomainType;
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
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true)
            && str_contains((string) $process->input, 'information_schema.schemata')) {
            return Process::result(output: '1');
        }

        // `wp option get blog_public` — production is indexable by default,
        // and a push must leave it that way. An empty answer is treated as
        // "could not read" and refuses the push, so the fake has to answer.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
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
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

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
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

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
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

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
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

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
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

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
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

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
            // Production's search-engine visibility. Read before the database is
            // replaced and written back after, so a push cannot de-index a live
            // site — an unreadable value refuses the push outright.
            if (in_array('option', $args, true) && in_array('get', $args, true)) {
                return Process::result(output: "1\n");
            }

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
            // Production's search-engine visibility. Read before the database is
            // replaced and written back after, so a push cannot de-index a live
            // site — an unreadable value refuses the push outright.
            if (in_array('option', $args, true) && in_array('get', $args, true)) {
                return Process::result(output: "1\n");
            }

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

        // Production's search-engine visibility, read before the database is
        // replaced and asserted after it is put back.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
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
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

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

/*
 * `database` mode: the third option, for moving content without touching the
 * files production is running.
 *
 * The half it moves is the half that cannot restore itself, so it dumps
 * first — but it must not snapshot the document root, because nothing in
 * this mode can write to it and copying it would be a slow way to protect
 * files that cannot change.
 */

it('pushes the database without touching production files', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'database'])
        ->assertOk();

    // No sync at all — not the push copy, and not the file snapshot either.
    expect(collect($commands)->contains(fn (array $args) => ($args[0] ?? '') === 'rsync'))->toBeFalse();

    // ...but the database did move.
    expect(collect($commands)->contains(fn (array $args) => ($args[0] ?? '') === 'mysqldump'))->toBeTrue();
});

it('dumps a safety copy before replacing the database', function () {
    // This mode replaces the half holding orders and customers. Losing it is
    // not recoverable by regeneration the way transients are.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $dumps = [];
    Process::fake(function ($process) use (&$dumps) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

        if (($args[0] ?? '') === 'mysqldump') {
            $dumps[] = implode(' ', $args);
        }

        if (($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), 'wp-config.php')) {
            return Process::result(output: "<?php\ndefine('DB_NAME', 'shop_db');\n");
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'database'])
        ->assertOk();

    // The safety dump lands under the site's own .panel directory; the push
    // dump goes to /tmp. Both run, and the safety one is what rollback needs.
    expect(collect($dumps)->contains(fn (string $d) => str_contains($d, '.panel/staging-backups')))->toBeTrue();
});

it('rewrites the URLs and flushes on a database push', function () {
    // The database arriving from staging carries staging's URLs and staging's
    // permalink rules, exactly as in a full push.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'database'])->assertOk();

    $searches = searchReplaceTerms($commands);
    $wp = wpCommands($commands);

    expect($searches)->toContain('https://staging.shop.test')
        ->and($searches)->toContain('staging.shop.test')
        ->and(collect($wp)->contains(fn (string $c) => str_contains($c, 'rewrite flush')))->toBeTrue()
        ->and(collect($wp)->contains(fn (string $c) => str_contains($c, 'cache flush')))->toBeTrue();
});

it('restores the database but not the files when a database push fails', function () {
    // There is no file snapshot in this mode, so the rollback must not try to
    // restore one — doing so would overwrite the live document root with an
    // empty directory.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $restores = [];
    Process::fake(function ($process) use (&$restores) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        // Production's search-engine visibility. Read before the database is
        // replaced and written back after, so a push cannot de-index a live
        // site — an unreadable value refuses the push outright.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

        if (($args[0] ?? '') === 'rsync') {
            $restores[] = implode(' ', $args);
        }

        // Fail the URL rewrite, which is inside the strategy's push.
        if (in_array('search-replace', $args, true)) {
            return Process::result(exitCode: 1, errorOutput: 'wp failed');
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'database'])
        ->assertStatus(500);

    // No rsync ran at all: not to snapshot, and not to restore.
    expect($restores)->toBeEmpty()
        // The site is not left behind a maintenance page.
        ->and($this->production->fresh()->disabled_at)->toBeNull();
});

it('accepts all three modes and refuses anything else', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $ignored = [];
    recordStagingPush($ignored);

    foreach (['files', 'database', 'full'] as $mode) {
        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/push', ['mode' => $mode])
            ->assertOk();
    }

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'everything'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mode');
});

/*
 * Search-engine visibility, in both directions.
 *
 * A staging site is the same content on a second address, which to a search
 * engine is the same page published twice: the copy gets indexed, splits the
 * ranking signals, and can outrank the original.
 *
 * Fixing that creates the opposite hazard, and it is the worse of the two.
 * Once staging is `blog_public = 0`, every database push carries that row
 * onto production — de-indexing a live site with no error, no visible change,
 * and nobody noticing until the traffic goes. So production's own value is
 * read before the database is replaced and written back afterwards.
 */

it('hides a new staging site from search engines', function () {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $commands[] = $args;

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: 0);
        }

        // Generating the staging database name asks the live server whether
        // the identifier is free; without this, creation fails before any of
        // the WordPress work runs.
        if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true)
            && str_contains((string) $process->input, 'information_schema.schemata')) {
            return Process::result(output: '1');
        }

        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])
        ->assertCreated();

    // The setting, so the WordPress admin agrees with reality...
    $sets = collect($commands)
        ->filter(fn (array $a) => in_array('option', $a, true) && in_array('update', $a, true))
        ->map(fn (array $a) => implode(' ', $a));

    expect($sets->contains(fn (string $c) => str_contains($c, 'blog_public 0')))->toBeTrue();

    // ...and the file, which a database import cannot undo.
    $written = collect($commands)
        ->filter(fn (array $a) => ($a[0] ?? '') === 'tee')
        ->map(fn (array $a) => (string) ($a[1] ?? ''));

    expect($written->contains(fn (string $path) => str_contains($path, 'panel-staging-noindex.php')))->toBeTrue();
});

it('never copies the noindex plugin onto production', function () {
    // If it crossed, the live site would be told not to index itself.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertOk();

    expect(pushRsyncArgs($commands))->toContain('wp-content/mu-plugins/panel-staging-noindex.php');
});

it('puts production back the way it was: indexable stays indexable', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertOk();

    // The database that landed says 0 because staging is always hidden; the
    // push must write production's own 1 back over it.
    $sets = collect($commands)
        ->filter(fn (array $a) => in_array('option', $a, true) && in_array('update', $a, true))
        ->map(fn (array $a) => implode(' ', $a));

    expect($sets->contains(fn (string $c) => str_contains($c, 'blog_public 1')))->toBeTrue();
});

it('puts production back the way it was: hidden stays hidden', function () {
    // A site whose owner chose to keep it out of search must not be exposed
    // by a push either. The rule is "restore what was there", not "force 1".
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $commands[] = $args;

        if (($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), 'wp-config.php')) {
            return Process::result(output: "<?php\ndefine('DB_NAME', 'shop_db');\n");
        }

        // This production site is deliberately not indexed.
        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "0\n");
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertOk();

    $sets = collect($commands)
        ->filter(fn (array $a) => in_array('option', $a, true) && in_array('update', $a, true))
        ->map(fn (array $a) => implode(' ', $a));

    expect($sets->contains(fn (string $c) => str_contains($c, 'blog_public 0')))->toBeTrue()
        ->and($sets->contains(fn (string $c) => str_contains($c, 'blog_public 1')))->toBeFalse();
});

it('refuses the push when production visibility cannot be read', function () {
    // Guessing is worse than stopping. Forcing 1 exposes a site its owner
    // hid; letting staging's 0 through de-indexes a live one. Neither is a
    // decision the panel should make on a wp-cli hiccup.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $dumps = [];
    Process::fake(function ($process) use (&$dumps) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'mysqldump') {
            $dumps[] = implode(' ', $args);
        }

        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(exitCode: 1, errorOutput: 'Error: The site you have requested is not installed.');
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'full'])
        ->assertStatus(500);

    // It stops before the staging dump is restored over production: the only
    // dump that ran is the safety copy.
    expect(collect($dumps)->filter(fn (string $d) => str_contains($d, '/tmp/panel-staging-push')))->toBeEmpty();
});

it('gives the staging site a primary domain row, not just the mirror column', function () {
    // The Domains screen reads `application_domains`; `applications.domain` is
    // only the mirror of whichever row is primary. CreateApplication writes
    // that row, StagingManager does not go through it, and staging came up
    // serving its domain with an empty Domains section.
    //
    // Worse than cosmetic: `serverNames()` falls back to the column only while
    // the relation is empty, so adding one alias afterwards made the relation
    // non-empty without the primary in it and the next vhost dropped the
    // staging site's own domain.
    fakeStagingServer();

    $response = $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl(), ['domain' => 'staging.domains.test'])
        ->assertCreated();

    $staging = Application::find($response->json('staging.id'));
    $primary = $staging->domains()->where('type', 'primary')->first();

    expect($primary)->not->toBeNull()
        ->and($primary->domain)->toBe('staging.domains.test')
        ->and($staging->domain)->toBe($primary->domain);

    $staging->domains()->create([
        'domain' => 'extra.staging.domains.test',
        'type' => DomainType::Alias,
        'is_test' => false,
    ]);

    expect($staging->fresh()->load('domains')->serverNames())
        ->toContain('staging.domains.test');
});
