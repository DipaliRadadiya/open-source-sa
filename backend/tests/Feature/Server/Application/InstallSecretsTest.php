<?php

use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\Installers\WordPressInstaller;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * A one-click site's admin password was saved into `applications.settings`,
 * plain JSON, and `GET /applications/{id}` returned it to anyone allowed to
 * view the site, for as long as the site existed. Found live on 2026-09-24:
 * a WordPress site's admin password came back in the API response.
 *
 * The installer needs it until the install succeeds and never again, so it now
 * lives in the encrypted `install_secrets`, is never serialized, and is cleared
 * when provisioning succeeds.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);
});

function secretsHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function secretsCapableServer(): void
{
    fakeUsableSqlEngine();

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);
}

function secretsWordPressSite(array $overrides = []): Application
{
    return Application::forceCreate(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Blog',
        'slug' => 'blog',
        'domain' => 'blog.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => ['site_title' => 'Blog', 'admin_user' => 'admin', 'admin_email' => 'a@example.com'],
        'install_secrets' => ['admin_password' => 'the-admin-password'],
    ], $overrides));
}

// The list is kept by hand because building a type's fields runs commands on
// the server. This is what keeps it honest: a type that adds a password field
// and forgets the list fails here, rather than shipping a password in `settings`.
it('lists every password field a site type declares as an install secret', function () {
    $declared = collect(app(SiteTypeManager::class)->all())
        ->flatMap(fn ($type) => $type->fields())
        ->where('type', 'password')
        ->pluck('name')
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($declared)->not->toBeEmpty()
        ->and(collect(Application::INSTALL_SECRET_KEYS)->sort()->values()->all())->toBe($declared);
});

it('keeps the admin password out of settings and out of the response when a site is created', function () {
    Queue::fake();
    secretsCapableServer();

    $response = $this->withHeaders(secretsHeaders())->postJson('/api/applications', [
        'site_type' => 'wordpress',
        'name' => 'Blog',
        'domain' => 'blog.example.com',
        'system_user_id' => $this->su->id,
        'site_title' => 'Blog',
        'admin_user' => 'admin',
        'admin_email' => 'a@example.com',
        'admin_password' => 'the-admin-password',
    ])->assertCreated();

    expect($response->getContent())->not->toContain('the-admin-password');

    $row = DB::table('applications')->first();

    // Not in the plain column, and not readable in the encrypted one either.
    expect($row->settings)->not->toContain('the-admin-password')
        ->and($row->install_secrets)->not->toBeNull()
        ->and($row->install_secrets)->not->toContain('the-admin-password');

    // Still there for the installer, which is the reason it is stored at all.
    expect(Application::first()->installSettings())
        ->toMatchArray(['admin_password' => 'the-admin-password', 'site_title' => 'Blog']);
});

it('never returns a password that is still in settings from before the fix', function () {
    $app = secretsWordPressSite([
        'status' => 'active',
        'settings' => ['site_title' => 'Blog', 'admin_password' => 'legacy-password', 'mailer_password' => 'legacy-mailer'],
        'install_secrets' => null,
    ]);

    foreach (["/api/applications/{$app->id}", '/api/applications'] as $url) {
        $response = $this->withHeaders(secretsHeaders())->getJson($url)->assertOk();

        expect($response->getContent())->not->toContain('legacy-password')
            ->and($response->getContent())->not->toContain('legacy-mailer')
            ->and($response->getContent())->toContain('"site_title":"Blog"');
    }
});

it('forgets the password once the install succeeds', function () {
    Process::fake();
    // A PHP site: the clearing is not the installer's business, and a type
    // without an installer keeps the test on the job's success path.
    $app = secretsWordPressSite(['site_type' => 'php']);

    (new ProvisionApplication($app->id))->handle(app(ApplicationProvisioner::class), app(ActivityLogger::class));

    $app->refresh();

    expect($app->status->value)->toBe('active')
        ->and($app->install_secrets)->toBeNull()
        ->and(DB::table('applications')->value('install_secrets'))->toBeNull();
});

it('keeps the password when the install fails, so Retry setup still works', function () {
    Process::fake(fn ($process) => $process->command[0] === 'mkdir'
        ? Process::result(errorOutput: 'permission denied', exitCode: 1)
        : Process::result());

    $app = secretsWordPressSite(['site_type' => 'php']);

    (new ProvisionApplication($app->id))->handle(app(ApplicationProvisioner::class), app(ActivityLogger::class));

    $app->refresh();

    expect($app->status->value)->toBe('failed')
        ->and($app->install_secrets)->toBe(['admin_password' => 'the-admin-password']);
});

it('hands the installer a password it holds only in install_secrets', function () {
    $ran = collect();
    Process::fake(function ($process) use ($ran) {
        $ran->push($process);

        return Process::result();
    });

    $app = secretsWordPressSite();

    app(WordPressInstaller::class)->install($app, '/home/deploy/blog/public_html', [
        'database' => 'blog', 'db_user' => 'blog', 'db_password' => 'db-secret', 'host' => '127.0.0.1',
    ]);

    // On stdin, as the installer has always sent it, never as an argument.
    $install = $ran->first(fn ($p) => in_array('install', (array) $p->command, true)
        && in_array('core', (array) $p->command, true));

    expect($install)->not->toBeNull()
        ->and($install->input)->toContain('the-admin-password')
        ->and(implode(' ', (array) $install->command))->not->toContain('the-admin-password');
});

it('stores a password sent through an update where the installer reads it, not in settings', function () {
    $app = secretsWordPressSite(['status' => 'failed', 'install_secrets' => null]);

    $response = $this->withHeaders(secretsHeaders())->putJson("/api/applications/{$app->id}", [
        'settings' => ['admin_password' => 'retry-password', 'site_title' => 'Renamed'],
    ])->assertOk();

    $app->refresh();

    expect($response->getContent())->not->toContain('retry-password')
        ->and($app->settings)->not->toHaveKey('admin_password')
        ->and($app->settings['site_title'])->toBe('Renamed')
        ->and($app->install_secrets)->toBe(['admin_password' => 'retry-password']);
});

describe('the migration', function () {
    beforeEach(function () {
        $this->migration = require database_path('migrations/2026_09_24_090000_move_install_secrets_out_of_application_settings.php');
        // Back to the shape the migration was written against.
        $this->migration->down();
    });

    function legacyRow(string $status, array $settings): int
    {
        return DB::table('applications')->insertGetId([
            'system_user_id' => test()->su->id, 'name' => $status, 'slug' => $status, 'domain' => "{$status}.example.com",
            'site_type' => 'wordpress', 'serving_profile' => 'php', 'web_root' => '/', 'status' => $status,
            'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    it('drops the password from installed sites and encrypts it for ones still to install', function () {
        $installed = legacyRow('active', ['site_title' => 'A', 'admin_password' => 'old-active']);
        $failed = legacyRow('failed', ['site_title' => 'F', 'admin_password' => 'old-failed', 'mailer_password' => 'old-mailer']);
        $plain = legacyRow('pending', ['site_title' => 'P']);

        $this->migration->up();

        $rows = DB::table('applications')->get()->keyBy('id');

        expect($rows[$installed]->settings)->not->toContain('old-active')
            ->and($rows[$installed]->install_secrets)->toBeNull()
            ->and(json_decode($rows[$installed]->settings, true))->toBe(['site_title' => 'A']);

        expect($rows[$failed]->settings)->not->toContain('old-failed')
            ->and($rows[$failed]->install_secrets)->not->toContain('old-failed')
            ->and(Application::find($failed)->install_secrets)->toBe(['admin_password' => 'old-failed', 'mailer_password' => 'old-mailer']);

        expect(json_decode($rows[$plain]->settings, true))->toBe(['site_title' => 'P'])
            ->and($rows[$plain]->install_secrets)->toBeNull();
    });

    it('puts back what it still holds when rolled back', function () {
        $failed = legacyRow('failed', ['site_title' => 'F', 'admin_password' => 'old-failed']);

        $this->migration->up();
        $this->migration->down();

        expect(json_decode(DB::table('applications')->where('id', $failed)->value('settings'), true))
            ->toBe(['site_title' => 'F', 'admin_password' => 'old-failed']);

        // Leave the schema as every other test expects it.
        $this->migration->up();
    });
});
