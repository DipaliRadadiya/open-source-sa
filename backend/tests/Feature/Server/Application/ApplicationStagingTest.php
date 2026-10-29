<?php

use App\Actions\Server\Application\PullStaging;
use App\Actions\Server\Application\PushStaging;
use App\Enums\BackupStatus;
use App\Enums\DomainType;
use App\Enums\RestoreStatus;
use App\Jobs\RunStagingSync;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Restore;
use App\Models\ServerCapability;
use App\Models\StagingRun;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\StagingManager;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\Storage\LocalBackupFolder;
use App\Services\Server\ServerOps;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

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

    // A 422 that says why (bug #88), not a 500 with a reference to quote.
    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl(), ['domain' => 'staging2.shop.test'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.application.0', __('errors/application.staging_exists'));

    expect(Application::where('production_application_id', $this->production->id)->count())->toBe(1);
});

it('refuses a staging copy of a staging copy (bug #89)', function () {
    fakeStagingServer();
    $stagingId = $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated()->json('staging.id');

    // Once the first staging is gone, a second-level copy is a normal-looking
    // site that still swallows every email and hides from search engines.
    $this->withHeaders(stagingHeaders())
        ->postJson("/api/applications/{$stagingId}/staging", ['domain' => 'staging.staging.shop.test'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.application.0', __('errors/application.staging_of_staging'));

    expect(Application::where('production_application_id', $stagingId)->exists())->toBeFalse();
});

it('refuses a push when there is no staging copy (bug #88)', function () {
    fakeStagingServer();

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'full'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.application.0', __('errors/application.staging_missing'));
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
        ->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

    expect($dumps)->toBeEmpty()
        ->and(ActivityLog::where('action', 'staging_pushed_files')->exists())->toBeTrue();

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
        ->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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
        ->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 500);

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
        ->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 500);

    // Four: production -> snapshot, staging -> production, the additive media
    // pass, and snapshot -> production on the way back out. This push gets
    // past the files and fails in the database half, so the media pass runs.
    expect($rsyncCalls)->toHaveCount(4)
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
        ->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 500)
        ->assertJsonPath('run.error.code', 'staging_rollback_failed')
        ->assertJsonPath('run.error.message', __('errors/application.staging_rollback_failed'));

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

            // Written as the site user now, so the command arrives wrapped.
            if (($args[0] ?? '') === 'runuser') {
                $args = array_slice($args, 4);
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
    })->with(['tee', 'chmod']);
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

it('points the new staging copy at itself under every spelling of the live address (bug #92)', function () {
    fakeStagingServer();

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $searched = fn (string $term, bool $regex = false) => Process::assertRan(function ($p) use ($term, $regex) {
        $i = array_search('search-replace', $p->command, true);

        return $i !== false && $p->command[$i + 1] === $term
            && in_array('--regex', $p->command, true) === $regex;
    });

    // Only `https://shop.test` was replaced, so a link saved over http://, in
    // the block editor's escaped form or as a bare host still led to live.
    $searched('https://shop.test');
    $searched('http://shop.test');
    $searched('http:\\/\\/shop.test');
    $searched('//shop.test');
    // staging.shop.test contains shop.test: a plain bare pass would have made
    // staging.staging.shop.test of every address already rewritten.
    $searched('(?<![A-Za-z0-9.-])shop\\.test(?![A-Za-z0-9-]|\\.[A-Za-z0-9])', regex: true);
    Process::assertNotRan(function ($p) {
        $i = array_search('search-replace', $p->command, true);

        return $i !== false && $p->command[$i + 1] === 'shop.test';
    });
});

it('never copies wp-config.php onto production', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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
        ->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 500);
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
        ->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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
        ->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'database'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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
        ->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 500);

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
            ->assertStatus(202)->assertJsonPath('run.status', 'succeeded');
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
    // Written as the staging site's own user, not root.
    $written = collect($commands)
        ->filter(fn (array $a) => ($a[0] ?? '') === 'runuser' && ($a[4] ?? '') === 'tee')
        ->map(fn (array $a) => (string) ($a[5] ?? ''));

    expect($written->contains(fn (string $path) => str_contains($path, 'panel-staging-noindex.php')))->toBeTrue();
});

it('never copies the noindex plugin onto production', function () {
    // If it crossed, the live site would be told not to index itself.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

    expect(pushRsyncArgs($commands))->toContain('wp-content/mu-plugins/panel-staging-noindex.php');
});

it('puts production back the way it was: indexable stays indexable', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

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
        ->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 500);

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

/*
 * Media.
 *
 * Reported from a live site: media uploaded in staging appeared in
 * production's Media Library after a push and every image 404'd. WordPress
 * stores an upload as two things — a row in `wp_posts` and a file under
 * `wp-content/uploads` — and the database half crosses in a push whether
 * anyone asks it to or not. `wp-content/uploads/` was in `syncExcludes()`, so
 * the file half never crossed at all: the library was reading rows that
 * described files which did not exist.
 *
 * The exclusion was not wrong, only half right. The main sync carries
 * `--delete`, and a photo added to production while somebody worked in
 * staging must survive a push. So uploads stay out of *that* pass and get an
 * additive one of their own.
 */

/** The additive media pass, if it ran. Distinguished by its destination. */
function mergeRsyncArgs(array $commands, string $path = 'wp-content/uploads/'): array
{
    foreach ($commands as $args) {
        if (($args[0] ?? '') === 'rsync' && str_ends_with((string) end($args), $path)) {
            return $args;
        }
    }

    return [];
}

it('carries uploaded media to production, without deleting production media', function () {
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

    $merge = mergeRsyncArgs($commands);

    expect($merge)->not->toBeEmpty()
        // The whole point of the second pass: production keeps media the
        // staging site has never seen. A push must not be able to destroy
        // files it has no copy of.
        ->and($merge)->not->toContain('--delete')
        ->and(end($merge))->toEndWith('/public_html/wp-content/uploads/')
        // And the main pass still excludes it, or the --delete would have
        // wiped them a step earlier.
        ->and(pushRsyncArgs($commands))->toContain('wp-content/uploads/');
});

it('carries existing media into a new staging site', function () {
    // The direction nobody notices until they look: a staging site cloned
    // from a real shop had no media at all, so every existing image was
    // broken in staging from the moment it was created.
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $commands[] = $args;

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: 0);
        }

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

    expect(mergeRsyncArgs($commands))->not->toBeEmpty();
});

it('copies media before the destination is chowned, not after', function () {
    // The merge pass writes as root. After the recursive chown, a pushed image
    // stays root-owned — present, served, and impossible to replace or delete
    // from the Media Library.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];
    recordStagingPush($commands);

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

    $mergeAt = null;
    $chownAt = null;

    foreach ($commands as $index => $args) {
        if ($mergeAt === null && ($args[0] ?? '') === 'rsync' && str_ends_with((string) end($args), 'wp-content/uploads/')) {
            $mergeAt = $index;
        }

        if ($mergeAt !== null && $chownAt === null
            && ($args[0] ?? '') === 'chown' && ($args[1] ?? '') === '-R'
            && str_ends_with((string) end($args), '/public_html')) {
            $chownAt = $index;
        }
    }

    expect($mergeAt)->not->toBeNull()
        ->and($chownAt)->not->toBeNull()
        ->and($mergeAt)->toBeLessThan($chownAt);
});

it('pushes a site that has never had an upload', function () {
    // rsync exits non-zero on a missing source, and a site whose only fault is
    // that nobody has added a photo yet has no uploads directory. Failing the
    // push for that would be the fix causing a worse bug than the one it fixed.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $commands[] = $args;

        if (($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), 'wp-config.php')) {
            return Process::result(output: "<?php\ndefine('DB_NAME', 'shop_db');\n");
        }

        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

        // No uploads directory. Silent exit 1 — which is what `test` actually
        // does when the answer is simply no.
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-d') {
            return Process::result(exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

    expect(mergeRsyncArgs($commands))->toBeEmpty();
});

it('refuses the push when it cannot tell whether media exists', function () {
    // `test -d` exits 1 for "not there" and sudo exits 1 for "you may not ask".
    // Reading the second as the first would skip the media silently and report
    // a clean push — the original bug, with a new hiding place. Anything on
    // stderr means the question was never answered.
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), 'wp-config.php')) {
            return Process::result(output: "<?php\ndefine('DB_NAME', 'shop_db');\n");
        }

        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-d') {
            return Process::result(exitCode: 1, errorOutput: 'sudo: a password is required');
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())
        ->postJson(stagingUrl().'/push', ['mode' => 'files'])
        ->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 500);
});

describe('one push at a time', function () {
    // Pushes ran inside the request with no lock: a double-click started two
    // that overwrote and rolled back the same production site at once, and
    // nothing stopped one during a restore (found in code review 2026-09-29).
    it('refuses a second push while one is running', function () {
        fakeStagingServer();
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

        $held = Cache::lock('staging-push:'.$this->production->id, 60);
        $held->get();

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/push', ['mode' => 'files'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/application.staging_push_running')]);

        $held->release();

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');
    });

    it('refuses a push while a restore of production is running', function () {
        fakeStagingServer();
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

        Restore::forceCreate(['application_id' => $this->production->id, 'status' => RestoreStatus::Running, 'type' => 'full']);

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/push', ['mode' => 'files'])
            ->assertStatus(422);
    });

    it('marks the push as running for the backup job, and clears it after', function () {
        fakeStagingServer();
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

        $seen = false;
        $id = $this->production->id;
        Process::fake(function ($process) use (&$seen, $id) {
            $seen = $seen || StagingManager::pushInProgress($id);
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            return in_array('option', $args, true) && in_array('get', $args, true)
                ? Process::result(output: "1\n")
                : Process::result(exitCode: 0);
        });

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

        expect($seen)->toBeTrue()
            ->and(StagingManager::pushInProgress($id))->toBeFalse();
    });
});

it('keeps only the newest pre-push database dumps', function () {
    // Each is a full copy of production's database, written before every
    // push and never removed (found in code review 2026-09-29).
    fakeStagingServer();
    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $removed = [];
    Process::fake(function ($process) use (&$removed) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (in_array('option', $args, true) && in_array('get', $args, true)) {
            return Process::result(output: "1\n");
        }

        if (($args[0] ?? '') === 'find' && in_array('pre-push-*.sql', $args, true)) {
            return Process::result(output: implode("\n", [
                'pre-push-20260901-100000-aaaaaa.sql',
                'pre-push-20260929-100000-eeeeee.sql',
                'pre-push-20260915-100000-cccccc.sql',
                'pre-push-20260910-100000-bbbbbb.sql',
                'pre-push-20260920-100000-dddddd.sql',
            ])."\n");
        }

        if (($args[0] ?? '') === 'rm' && ($args[1] ?? '') === '-f') {
            $removed = array_map('basename', array_slice($args, 3));
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

    sort($removed);
    expect($removed)->toBe(['pre-push-20260901-100000-aaaaaa.sql', 'pre-push-20260910-100000-bbbbbb.sql']);
});

it('refuses a staging domain that is already another site name or the panel host', function () {
    fakeStagingServer();
    $this->production->domains()->create(['domain' => 'www.shop.test', 'type' => DomainType::Alias]);
    config(['server.storage.panel_url' => 'https://panel.shop.test']);

    // An alias lives only in application_domains, so the old check against
    // applications.domain let it through to the insert.
    foreach (['www.shop.test', 'panel.shop.test'] as $domain) {
        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl(), ['domain' => $domain])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    expect(Application::where('production_application_id', $this->production->id)->count())->toBe(0);
});

describe('a backup before the push (FS-B10)', function () {
    beforeEach(function () {
        fakeStagingServer();
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

        $destination = StorageDestination::create([
            'name' => 'Offsite', 'provider' => 's3',
            'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
        ]);
        $this->target = BackupTarget::create([
            'application_id' => $this->production->id, 'storage_destination_id' => $destination->id,
            'type' => 'full', 'retention_count' => 7, 'frequency' => 'daily', 'enabled' => true,
        ]);
    });

    function stagingBackupRunner(BackupStatus $status): void
    {
        $runner = Mockery::mock(BackupRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(fn (BackupTarget $target, ?int $actor) => Backup::create([
            'backup_target_id' => $target->id, 'application_id' => $target->application_id, 'user_id' => $actor,
            'type' => 'full', 'is_safety' => false, 'status' => $status,
            'reason' => $status === BackupStatus::Failed ? 'upload_failed' : null,
        ]));
        app()->instance(BackupRunner::class, $runner);
    }

    it('takes a backup first and names it in the answer', function () {
        stagingBackupRunner(BackupStatus::Verified);

        $response = $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/push', ['mode' => 'files', 'backup' => true])
            ->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

        expect($response->json('run.backup_id'))->toBe(Backup::sole()->id)
            ->and(ActivityLog::where('action', 'completed')->where('type', 'backup')->exists())->toBeTrue()
            ->and(ActivityLog::where('action', 'staging_pushed_files')->exists())->toBeTrue();
    });

    it('does not push when the backup failed', function () {
        stagingBackupRunner(BackupStatus::Failed);

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/push', ['mode' => 'files', 'backup' => true])
            ->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 409)
            ->assertJsonPath('run.error.reason', 'backup_failed')
            ->assertJsonPath('run.error.backup_id', Backup::sole()->id)
            ->assertJsonPath('run.error.backup_reason', 'upload_failed');

        // Never even taken offline for the push.
        expect(ActivityLog::where('action', 'staging_pushed_files')->exists())->toBeFalse()
            ->and(StagingManager::pushInProgress($this->production->id))->toBeFalse()
            ->and($this->production->fresh()->disabled_at)->toBeNull();
    });

    it('answers null without one, and runs no backup', function () {
        app()->instance(BackupRunner::class, Mockery::mock(BackupRunner::class)->shouldNotReceive('run')->getMock());

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/push', ['mode' => 'files'])
            ->assertStatus(202)->assertJsonPath('run.status', 'succeeded')
            ->assertJsonPath('run.backup_id', null);
    });

    it('refuses before pushing when backups are not set up', function () {
        $this->target->delete();

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/push', ['mode' => 'files', 'backup' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('backup');

        expect(ActivityLog::where('action', 'staging_pushed_files')->exists())->toBeFalse();
    });

    it('needs the backup permission as well as staging', function () {
        $user = User::factory()->create();
        grantPermission($user, 'app_staging', manage: true);

        $this->actingAs($user)
            ->postJson(stagingUrl().'/push', ['mode' => 'files', 'backup' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('backup');

        expect(Backup::count())->toBe(0);
    });
});

describe('the database copies a push saves first (ST-B3)', function () {
    function fakeSafetyCopies(array &$restores, array &$dumps, array $names): void
    {
        Process::fake(function ($process) use (&$restores, &$dumps, $names) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($args[0] ?? '') === 'find' && in_array('%f\t%s\t%T@\n', $args, true)) {
                return Process::result(output: implode('', array_map(fn ($n) => "{$n}\t2048\t1791460800.5\n", $names)));
            }

            if (($args[0] ?? '') === 'mysqldump') {
                $dumps[] = $args;
            }

            if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true) && in_array('-e', $args, true)) {
                $restores[] = (string) ($args[array_search('-e', $args, true) + 1] ?? '');
            }

            return Process::result();
        });
    }

    beforeEach(function () {
        $this->copies = ['pre-push-20261001-101500-abc123.sql', 'pre-push-20261003-090000-zzz999.sql', 'notes.txt'];
    });

    it('lists them newest first, and nothing that is not one', function () {
        $restores = $dumps = [];
        fakeSafetyCopies($restores, $dumps, $this->copies);

        $this->withHeaders(stagingHeaders())->getJson(stagingUrl().'/safety-copies')
            ->assertOk()
            ->assertJsonCount(2, 'safety_copies')
            ->assertJsonPath('safety_copies.0.name', 'pre-push-20261003-090000-zzz999.sql')
            ->assertJsonPath('safety_copies.0.size_bytes', 2048)
            ->assertJsonPath('kept', StagingManager::KEEP_PRE_PUSH_DUMPS);
    });

    it('puts one back, saving the database as it is now first', function () {
        $restores = $dumps = [];
        fakeSafetyCopies($restores, $dumps, $this->copies);

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/safety-copies/pre-push-20261001-101500-abc123.sql/restore')
            ->assertOk()
            ->assertJsonPath('restored', 'pre-push-20261001-101500-abc123.sql');

        expect($dumps)->toHaveCount(1)
            ->and($restores)->toHaveCount(1)
            ->and($restores[0])->toContain('/staging-backups/pre-push-20261001-101500-abc123.sql')
            ->and(ActivityLog::where('action', 'staging_safety_copy_restored')->exists())->toBeTrue();
    });

    it('refuses a name that is not one of them, or a path', function (string $name) {
        $restores = $dumps = [];
        fakeSafetyCopies($restores, $dumps, $this->copies);

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/safety-copies/'.rawurlencode($name).'/restore')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        expect($restores)->toBeEmpty()->and($dumps)->toBeEmpty();
    })->with(['pre-push-20260101-000000-gone00.sql', 'notes.txt', '..%2F..%2Fetc%2Fshadow']);

    it('refuses while a push is running', function () {
        $restores = $dumps = [];
        fakeSafetyCopies($restores, $dumps, $this->copies);
        $lock = Cache::lock('staging-push:'.$this->production->id, 60);
        $lock->get();

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/safety-copies/pre-push-20261001-101500-abc123.sql/restore')
            ->assertUnprocessable();

        $lock->release();
        expect($restores)->toBeEmpty();
    });

    it('lets a viewer list but not restore', function () {
        $restores = $dumps = [];
        fakeSafetyCopies($restores, $dumps, $this->copies);
        $viewer = User::factory()->create();
        grantPermission($viewer, 'app_staging');

        $this->actingAs($viewer)->getJson(stagingUrl().'/safety-copies')->assertOk();
        $this->actingAs($viewer)->postJson(stagingUrl().'/safety-copies/pre-push-20261001-101500-abc123.sql/restore')->assertForbidden();

        expect($restores)->toBeEmpty();
    });
});

/*
 * Update staging from live (v7's sync-to-staging): the reverse of a push.
 * What matters: staging is replaced from live and stays staging — its own
 * database, URL, mail trap and noindex — live is only read, staging is saved
 * first and put back on failure, and a push and a pull never run together.
 */
/** Commands a pull ran, in order — a static, because a Pest test's properties cannot be appended to from inside a fake. */
final class PullHost
{
    /** @var array<int, array{args: array<int, string>, input: string}> */
    public static array $runs = [];
}

describe('updating staging from live', function () {
    beforeEach(function () {
        fakeStagingServer();
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();
        $this->staging = Application::where('production_application_id', $this->production->id)->first();
        $this->stagingDb = Database::where('application_id', $this->staging->id)->first();
        PullHost::$runs = [];
    });

    /** The server during a pull: staging answers `blog_public = 0` unless told otherwise. */
    function fakePullServer(string $visibility = '0', ?callable $override = null): void
    {
        Process::fake(function ($process) use ($visibility, $override) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            PullHost::$runs[] = ['args' => $args, 'input' => (string) $process->input];

            if ($override !== null && ($result = $override($args)) !== null) {
                return $result;
            }

            if (in_array('option', $args, true) && in_array('get', $args, true)) {
                return Process::result(output: $visibility."\n");
            }

            return Process::result(exitCode: 0);
        });
    }

    function pullRan(string $needle): ?int
    {
        foreach (PullHost::$runs as $i => $run) {
            if (str_contains(implode(' ', $run['args']).' '.$run['input'], $needle)) {
                return $i;
            }
        }

        return null;
    }

    it('copies live into staging and leaves staging pointing at itself, hidden and mail-trapped', function () {
        fakePullServer();
        $live = '/home/siteowner/shop/public_html';
        $stage = "/home/siteowner/{$this->staging->slug}/public_html";

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/pull', ['mode' => 'full'])
            ->assertStatus(202)->assertJsonPath('run.status', 'succeeded')
            ->assertJsonPath('run.kind', 'pull');

        $rsync = collect(PullHost::$runs)->first(fn ($r) => ($r['args'][0] ?? '') === 'rsync' && in_array('--delete', $r['args'], true)
            && in_array("{$live}/", $r['args'], true));

        expect($rsync)->not->toBeNull()
            // Live → staging, never the other way, and staging's identity files stay out.
            ->and(array_slice($rsync['args'], -2))->toBe(["{$live}/", "{$stage}/"])
            ->and($rsync['args'])->toContain('wp-config.php', 'wp-content/mu-plugins/panel-staging-mail-trap.php')
            // Staging's own database dumped first, before live's is loaded into it.
            ->and(pullRan("--result-file=/home/siteowner/{$this->staging->slug}/.panel/staging-backups/pre-push-"))->not->toBeNull()
            ->and(pullRan('--result-file=/tmp/panel-staging-pull-'))->toBeGreaterThan(pullRan("/home/siteowner/{$this->staging->slug}/.panel/staging-backups/pre-push-"))
            ->and(pullRan('search-replace https://shop.test http://staging.shop.test --path=/home/siteowner/'.$this->staging->slug.'/public_html'))->not->toBeNull()
            ->and(pullRan('option update blog_public 0'))->not->toBeNull()
            // `tee`, not the bare name: that also matches the rsync exclude.
            ->and(pullRan("tee {$stage}/wp-content/mu-plugins/panel-staging-mail-trap.php"))->not->toBeNull()
            ->and(pullRan("tee {$stage}/wp-content/mu-plugins/panel-staging-noindex.php"))->not->toBeNull()
            ->and($this->production->fresh()->disabled_at)->toBeNull()
            ->and($this->staging->fresh()->disabled_at)->toBeNull()
            ->and(ActivityLog::where('action', 'staging_pulled_full')->exists())->toBeTrue();
    });

    it('never disables or writes to the live site', function () {
        fakePullServer();
        $live = '/home/siteowner/shop/public_html';

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/pull', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

        $writesLive = collect(PullHost::$runs)->contains(function ($r) use ($live) {
            $args = $r['args'];
            $last = (string) end($args);

            return (($args[0] ?? '') === 'rsync' && str_starts_with($last, $live))
                || (in_array('tee', $args, true) && str_starts_with($last, $live))
                || (in_array('search-replace', $args, true) && in_array("--path={$live}", $args, true));
        });

        expect($writesLive)->toBeFalse()
            ->and(pullRan('/home/siteowner/shop/.panel/staging-backups'))->toBeNull();
    });

    it('leaves the staging database alone on a files-only update', function () {
        fakePullServer();

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/pull', ['mode' => 'files'])->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

        expect(pullRan('mysqldump'))->toBeNull()
            ->and(pullRan('search-replace'))->toBeNull()
            ->and(pullRan("tee /home/siteowner/{$this->staging->slug}/public_html/wp-content/mu-plugins/panel-staging-mail-trap.php"))->not->toBeNull()
            ->and(ActivityLog::where('action', 'staging_pulled_files')->exists())->toBeTrue();
    });

    it('rolls staging back when it came out connected to the live database', function () {
        fakePullServer(override: fn (array $args) => ($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), $this->staging->slug.'/public_html/wp-config.php')
            ? Process::result(output: "<?php\ndefine('DB_NAME', 'shop_db');\n")
            : null);
        $stage = "/home/siteowner/{$this->staging->slug}/public_html";

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/pull', ['mode' => 'full'])->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 500);

        $restoredFiles = collect(PullHost::$runs)->contains(fn ($r) => ($r['args'][0] ?? '') === 'rsync'
            && str_contains(implode(' ', $r['args']), '/staging-rollbacks/') && end($r['args']) === "{$stage}/");

        expect($restoredFiles)->toBeTrue()
            ->and(pullRan('source /home/siteowner/'.$this->staging->slug.'/.panel/staging-backups/pre-push-'))->not->toBeNull()
            ->and($this->staging->fresh()->disabled_at)->toBeNull();
    });

    it('refuses to call it done if staging is visible to search engines afterwards', function () {
        fakePullServer('1');

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/pull', ['mode' => 'database'])->assertStatus(202)->assertJsonPath('run.status', 'failed')->assertJsonPath('run.error_status', 500);
    });

    it('cannot run while a push holds live, or while staging restores a safety copy', function (string $which) {
        fakePullServer();
        $id = $which === 'live' ? $this->production->id : $this->staging->id;
        $held = Cache::lock('staging-push:'.$id, 60);
        $held->get();

        $this->withHeaders(stagingHeaders())
            ->postJson(stagingUrl().'/pull', ['mode' => 'files'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('errors/application.staging_push_running')]);

        $held->release();
        // Neither lock is left behind by the refusal.
        expect(Cache::lock('staging-push:'.$this->production->id, 1)->get())->toBeTrue();
    })->with(['live', 'staging']);

    it('refuses while a restore of either site is running', function (string $which) {
        fakePullServer();
        Restore::forceCreate(['application_id' => $which === 'live' ? $this->production->id : $this->staging->id, 'status' => RestoreStatus::Running, 'type' => 'full']);

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/pull', ['mode' => 'files'])->assertStatus(422);
    })->with(['live', 'staging']);

    it('accepts the three modes only, needs a staging copy, and needs manage', function () {
        fakePullServer();

        // The test client keeps the first token's user for the rest of the
        // test (beforeEach created staging as admin), so forget it first.
        app('auth')->forgetGuards();
        $viewer = User::factory()->create();
        grantPermission($viewer, 'app_staging', view: true, manage: false);
        $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
            ->postJson(stagingUrl().'/pull', ['mode' => 'files'])->assertForbidden();
        app('auth')->forgetGuards();

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/pull', ['mode' => 'everything'])->assertStatus(422);

        $this->staging->delete();
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/pull', ['mode' => 'files'])
            ->assertStatus(422)->assertJsonValidationErrors('application');
    });
});

/*
 * RC-16 (frontend QA, 16 Oct): push and update-from-live ran inside the
 * request, so a large site answered 504 while the copy carried on and nothing
 * could say how it ended. They run on the queue now; the run is the answer.
 */
describe('push and update from live on the queue (RC-16)', function () {
    beforeEach(function () {
        fakeStagingServer();
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();
    });

    it('answers 202 with a queued run, and queues the job', function (string $kind) {
        Queue::fake();

        $response = $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/'.$kind, ['mode' => 'files'])
            ->assertStatus(202)
            ->assertJsonPath('run.kind', $kind)
            ->assertJsonPath('run.mode', 'files')
            ->assertJsonPath('run.status', 'queued');

        Queue::assertPushed(RunStagingSync::class, fn ($job) => $job->runId === $response->json('run.id'));
    })->with(['push', 'pull']);

    it('shows the latest run on the staging page and as its own resource', function () {
        $id = $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])->json('run.id');

        $this->withHeaders(stagingHeaders())->getJson(stagingUrl())
            ->assertOk()
            ->assertJsonPath('latest_run.id', $id)
            ->assertJsonPath('latest_run.status', 'succeeded');

        $this->withHeaders(stagingHeaders())->getJson(stagingUrl().'/runs/'.$id)
            ->assertOk()
            ->assertJsonPath('run.id', $id);
    });

    it('does not show one site\'s run under another', function () {
        $id = $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])->json('run.id');
        $staging = Application::where('production_application_id', $this->production->id)->first();

        $this->withHeaders(stagingHeaders())->getJson("/api/applications/{$staging->id}/staging/runs/{$id}")->assertNotFound();
    });

    it('refuses a second push while one is queued, before queueing anything', function () {
        Queue::fake();
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])->assertStatus(202);

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/pull', ['mode' => 'files'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');

        Queue::assertPushed(RunStagingSync::class, 1);
        expect(StagingRun::count())->toBe(1);
    });

    it('refuses while a restore of the live site is running', function () {
        Queue::fake();
        $backup = Backup::create(['application_id' => $this->production->id, 'type' => 'full', 'status' => BackupStatus::Verified]);
        Restore::create(['backup_id' => $backup->id, 'application_id' => $this->production->id, 'type' => 'full', 'status' => RestoreStatus::Running, 'reference' => 'r']);

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    });

    it('reports a run whose worker died as failed, and does not let it block the next', function () {
        $stranded = StagingRun::create(['application_id' => $this->production->id, 'kind' => 'push', 'mode' => 'files', 'status' => StagingRun::RUNNING]);
        $stranded->forceFill(['created_at' => now()->subSeconds(StagingRun::staleAfterSeconds() + 60)])->save();

        $this->withHeaders(stagingHeaders())->getJson(stagingUrl().'/runs/'.$stranded->id)
            ->assertJsonPath('run.status', 'failed')
            ->assertJsonPath('run.error.code', 'staging_run_lost');

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files'])
            ->assertStatus(202)->assertJsonPath('run.status', 'succeeded');
    });

    it('marks the run failed when the job itself dies', function () {
        $run = StagingRun::create(['application_id' => $this->production->id, 'kind' => 'push', 'mode' => 'files', 'status' => StagingRun::RUNNING]);

        (new RunStagingSync($run->id, $this->production->id))->failed(new RuntimeException('killed'));

        expect($run->fresh()->status)->toBe(StagingRun::FAILED)
            ->and($run->fresh()->error['code'])->toBe('staging_failed')
            ->and($run->fresh()->error['reference'])->not->toBeEmpty()
            ->and(json_encode($run->fresh()->error))->not->toContain('killed');
    });

    it('still names who pushed, from the queue where no one is signed in', function () {
        $run = StagingRun::create(['application_id' => $this->production->id, 'kind' => 'push', 'mode' => 'files', 'status' => StagingRun::QUEUED]);
        // As on a queue worker: the request that created staging signed the admin in.
        app()->instance('request', Request::create('/'));
        auth()->forgetGuards();
        expect(auth()->user())->toBeNull();

        app(RunStagingSync::class, ['runId' => $run->id, 'applicationId' => $this->production->id, 'actorId' => $this->admin->id])
            ->handle(app(PushStaging::class), app(PullStaging::class));

        expect($run->fresh()->status)->toBe(StagingRun::SUCCEEDED)
            ->and(ActivityLog::where('action', 'staging_pushed_files')->sole()->user_id)->toBe($this->admin->id);
    });
});

it('runs every wp-cli step on the site\'s own PHP', function () {
    config(['server.php_binary_pattern' => '/usr/bin/php{version}']);
    $this->production->forceFill(['php_version' => '7.4'])->save();
    fakeStagingServer();

    $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();

    $wp = (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp');
    $usesWp = fn ($p) => is_array($p->command) && in_array($wp, $p->command, true);
    $onSitePhp = fn ($p) => $p->command[array_search($wp, $p->command, true) - 1] === '/usr/bin/php7.4';

    Process::assertRan(fn ($p) => $usesWp($p) && in_array('search-replace', $p->command, true) && $onSitePhp($p));
    Process::assertNotRan(fn ($p) => $usesWp($p) && ! $onSitePhp($p));
});

describe('the safety copy switch on push and pull (2026-10-29)', function () {
    /** Every command run, flattened, with production's visibility answered. */
    function stagingCommandsSeen(string $visibility = '1'): ArrayObject
    {
        $seen = new ArrayObject;
        Process::fake(function ($process) use ($seen, $visibility) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $seen[] = implode(' ', $args);

            if (in_array('option', $args, true) && in_array('get', $args, true)) {
                return Process::result(output: $visibility."\n");
            }

            return Process::result(exitCode: 0);
        });

        return $seen;
    }

    // The dump itself, not the `find` that lists old ones to prune.
    $safetyDumps = fn (ArrayObject $seen) => collect($seen)->filter(fn (string $c) => str_contains($c, '--result-file=') && str_contains($c, 'pre-push-'))->count();

    beforeEach(function () {
        fakeStagingServer();
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl(), ['domain' => 'staging.shop.test'])->assertCreated();
    });

    it('still copies the database first when nothing is said', function () use ($safetyDumps) {
        $seen = stagingCommandsSeen();

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full'])
            ->assertStatus(202)->assertJsonPath('run.status', 'succeeded')->assertJsonPath('run.safety_copy', true);

        expect($safetyDumps($seen))->toBeGreaterThan(0);
    });

    it('pushes without the copy when the user turns it off, and says so in the log', function () use ($safetyDumps) {
        $seen = stagingCommandsSeen();

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full', 'safety_copy' => false])
            ->assertStatus(202)->assertJsonPath('run.status', 'succeeded')->assertJsonPath('run.safety_copy', false);

        expect($safetyDumps($seen))->toBe(0);
        $line = collect($this->withHeaders(stagingHeaders())->getJson('/api/activity-log?filter[type]=application&per_page=50')->json('activity_log'))
            ->firstWhere('action', 'staging_pushed_full');
        expect($line['description'])->toEndWith('(without a safety copy)');
    });

    it('pulls without the copy when the user turns it off', function () use ($safetyDumps) {
        // Staging is kept out of search engines: its visibility reads 0.
        $seen = stagingCommandsSeen('0');

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/pull', ['mode' => 'database', 'safety_copy' => false])
            ->assertStatus(202)->assertJsonPath('run.status', 'succeeded');

        expect($safetyDumps($seen))->toBe(0);
    });

    it('refuses the copy when the disk has no room for it, and replaces nothing', function () use ($safetyDumps) {
        $seen = stagingCommandsSeen();
        Database::where('application_id', $this->production->id)->update(['size_bytes' => 4096]);
        // 4095 bytes of headroom on the disk the copy would go to.
        $folder = Mockery::mock(LocalBackupFolder::class, [app(ServerOps::class), app(HomeDirectoryAccess::class)])->makePartial();
        $folder->shouldReceive('space')->andReturn(['free' => 1024 ** 3, 'total' => 100 * 1024 ** 3]);
        app()->instance(LocalBackupFolder::class, $folder);
        config(['server.backups.local.min_free_percent' => 0, 'server.backups.local.min_free_bytes' => 1024 ** 3 - 4095]);

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'database'])
            ->assertStatus(202)
            ->assertJsonPath('run.status', 'failed')
            ->assertJsonPath('run.error_status', 422)
            ->assertJsonPath('run.error.errors.safety_copy.0', __('errors/application.staging_not_enough_space'));

        expect($safetyDumps($seen))->toBe(0)
            // Nothing was loaded into the live database either.
            ->and(collect($seen)->first(fn (string $c) => str_contains($c, 'source ')))->toBeNull();
    });

    it('refuses to set the files aside when the disk has no room for them, and replaces nothing', function () {
        $seen = new ArrayObject;
        Process::fake(function ($process) use ($seen) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $seen[] = implode(' ', $args);

            return ($args[0] ?? '') === 'du'
                ? Process::result(output: "4096\t".end($args)."\n")
                : Process::result(exitCode: 0);
        });
        $folder = Mockery::mock(LocalBackupFolder::class, [app(ServerOps::class), app(HomeDirectoryAccess::class)])->makePartial();
        $folder->shouldReceive('space')->andReturn(['free' => 1024 ** 3, 'total' => 100 * 1024 ** 3]);
        app()->instance(LocalBackupFolder::class, $folder);
        config(['server.backups.local.min_free_percent' => 0, 'server.backups.local.min_free_bytes' => 1024 ** 3 - 4095]);

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'files', 'safety_copy' => false])
            ->assertStatus(202)
            ->assertJsonPath('run.status', 'failed')
            ->assertJsonPath('run.error_status', 422)
            ->assertJsonPath('run.error.errors.mode.0', __('errors/application.staging_files_not_enough_space'));

        expect(collect($seen)->first(fn (string $c) => str_starts_with($c, 'rsync ')))->toBeNull();
    });

    it('removes a copy that failed part-way', function () {
        $seen = new ArrayObject;
        Process::fake(function ($process) use ($seen) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $seen[] = implode(' ', $args);

            if (str_contains(implode(' ', $args), '--result-file=') && str_contains(implode(' ', $args), 'pre-push-')) {
                return Process::result(errorOutput: 'No space left on device', exitCode: 2);
            }

            return Process::result(exitCode: 0);
        });

        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'database'])
            ->assertJsonPath('run.status', 'failed');

        expect(collect($seen)->first(fn (string $c) => str_starts_with($c, 'rm -f -- ') && str_contains($c, '/staging-backups/pre-push-')))->not->toBeNull();
    });
    it('refuses a value that is not true or false', function () {
        $this->withHeaders(stagingHeaders())->postJson(stagingUrl().'/push', ['mode' => 'full', 'safety_copy' => 'maybe'])
            ->assertJsonValidationErrors('safety_copy');
    });
});
