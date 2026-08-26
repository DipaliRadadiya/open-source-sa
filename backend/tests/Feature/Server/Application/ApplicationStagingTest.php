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
