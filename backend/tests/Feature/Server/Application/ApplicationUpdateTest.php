<?php

use App\Enums\ApplicationUpdateStatus;
use App\Enums\BackupStatus;
use App\Jobs\UpdateApplication;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationUpdate;
use App\Models\Backup;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationUpdates;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * One-click update of a site's own software (v7 parity): Moodle, Mautic,
 * Statamic, Craft CMS, Uptime Kuma, phpMyAdmin, Adminer.
 *
 * What matters here: an update stays inside the installed major, every
 * command runs as the site's user on the site's own PHP, the files that hold
 * the site's configuration survive, success is judged by the version the
 * files carry afterwards, and a failure says which step and why.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    Cache::flush();

    $this->home = sys_get_temp_dir().'/sv-oss-update-'.getmypid();
    config(['server.installer_work_dir' => $this->home.'/work']);
    $this->systemUser = SystemUser::create(['username' => 'siteuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false]);

    UpdateHost::reset();
    Http::preventStrayRequests();
});

/** The server, as the updater sees it: version files that change once the update's last step ran. */
final class UpdateHost
{
    /** @var array<string, array{0: string, 1: string}> path => [before, after] */
    public static array $files = [];

    public static bool $updated = false;

    /** Command (joined) whose run flips the files to their "after" content. */
    public static ?string $finalStep = null;

    /** Command (joined, substring) that fails. */
    public static ?string $failOn = null;

    /** @var array<string, string> command substring => stdout */
    public static array $outputs = [];

    /** @var array<int, array{command: array<int, string>, path: ?string}> */
    public static array $runs = [];

    public static function reset(): void
    {
        self::$files = [];
        self::$updated = false;
        self::$finalStep = null;
        self::$failOn = null;
        self::$outputs = [];
        self::$runs = [];

        Process::fake(function ($process) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            self::$runs[] = ['command' => $args, 'path' => $process->path];
            $joined = implode(' ', $args);

            if (self::$failOn !== null && str_contains($joined, self::$failOn)) {
                return Process::result(errorOutput: 'simulated failure', exitCode: 1);
            }
            if (($args[0] ?? '') === 'cat' && ($args[1] ?? '') === '--') {
                $file = self::$files[$args[2]] ?? null;

                return $file === null ? Process::result(exitCode: 1) : Process::result(output: $file[self::$updated ? 1 : 0]);
            }
            foreach (self::$outputs as $needle => $output) {
                if (str_contains($joined, $needle)) {
                    return Process::result(output: $output);
                }
            }
            if (self::$finalStep !== null && str_contains($joined, self::$finalStep)) {
                self::$updated = true;
            }

            return Process::result();
        });
    }

    public static function ran(string $needle): bool
    {
        return collect(self::$runs)->contains(fn ($run) => str_contains(implode(' ', $run['command']), $needle));
    }

    /** @return array{command: array<int, string>, path: ?string}|null */
    public static function run(string $needle): ?array
    {
        return collect(self::$runs)->first(fn ($run) => str_contains(implode(' ', $run['command']), $needle));
    }
}

function updateApp(string $type, string $webRoot = '/', ?string $phpVersion = '8.3'): Application
{
    return Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => $type.'site',
        'slug' => $type.'site',
        'domain' => $type.'.example.com',
        'site_type' => $type,
        'serving_profile' => $type === 'uptimekuma' ? 'node' : 'php',
        'php_version' => $phpVersion,
        'web_root' => $webRoot,
        'status' => 'active',
    ]);
}

function updateHeaders(?User $user = null): array
{
    return ['Authorization' => 'Bearer '.($user ?? test()->admin)->createToken('t')->plainTextToken];
}

function runUpdateJob(ApplicationUpdate $update): ApplicationUpdate
{
    (new UpdateApplication($update->id, $update->application_id))->handle(app(ApplicationUpdates::class), app(ActivityLogger::class));

    return $update->fresh();
}

function pendingUpdate(Application $application, string $from, string $to): ApplicationUpdate
{
    return ApplicationUpdate::create([
        'application_id' => $application->id, 'user_id' => test()->admin->id,
        'from_version' => $from, 'to_version' => $to, 'status' => ApplicationUpdateStatus::Pending,
    ]);
}

function moodleFixture(Application $application): void
{
    UpdateHost::$files[$application->documentRoot().'/version.php'] = [
        "<?php\n\$version  = 2025041409.00;\n\$release  = '5.0.9+ (Build: 20260911)';\n",
        "<?php\n\$version  = 2025041411.00;\n\$release  = '5.0.11 (Build: 20261005)';\n",
    ];
    Http::fake([
        'api.github.com/repos/moodle/moodle/tags*' => Http::response([['name' => 'v5.3.0'], ['name' => 'v5.3.0-rc1'], ['name' => 'v5.0.11'], ['name' => 'v5.0.10'], ['name' => 'v4.5.7']]),
    ]);
}

// ---------------------------------------------------------------- status

it('says a site type it cannot update is unsupported', function () {
    $application = updateApp('php');

    $this->withHeaders(updateHeaders())->getJson("/api/applications/{$application->id}/update")
        ->assertOk()
        ->assertJsonPath('update.supported', false)
        ->assertJsonPath('update.blocked_reason', 'unsupported');
});

it('dates the last backup like every other date in the API (RC-10)', function () {
    $application = updateApp('php');
    $this->withHeaders(updateHeaders())->getJson("/api/applications/{$application->id}/update")
        ->assertJsonPath('update.last_backup_at', null);

    Backup::create(['application_id' => $application->id, 'type' => 'full', 'status' => BackupStatus::Verified->value,
        'finished_at' => Carbon::parse('2026-10-13 09:10:02')]);
    Backup::create(['application_id' => $application->id, 'type' => 'full', 'status' => BackupStatus::Failed->value,
        'finished_at' => Carbon::parse('2026-10-14 09:00:00')]);

    $this->withHeaders(updateHeaders())->getJson("/api/applications/{$application->id}/update")
        ->assertJsonPath('update.last_backup_at', '13-10-2026 09:10:02');
});

it('reports the installed version, the newest in its line, and a newer line it will not cross', function () {
    $application = updateApp('moodle');
    moodleFixture($application);

    $this->withHeaders(updateHeaders())->getJson("/api/applications/{$application->id}/update")
        ->assertOk()
        ->assertJsonPath('update.supported', true)
        ->assertJsonPath('update.installed_version', '5.0.9')
        ->assertJsonPath('update.latest_version', '5.0.11')
        ->assertJsonPath('update.newest_version', '5.3.0')
        ->assertJsonPath('update.newer_major', '5.3.0')
        ->assertJsonPath('update.update_available', true)
        ->assertJsonPath('update.blocked_reason', null);
});

it('says when the release lookup failed instead of calling the site up to date', function () {
    $application = updateApp('moodle');
    UpdateHost::$files[$application->documentRoot().'/version.php'] = ["<?php\n\$release  = '5.0.9+';\n", ''];
    Http::fake(['*' => Http::response('', 503)]);

    $this->withHeaders(updateHeaders())->getJson("/api/applications/{$application->id}/update")
        ->assertOk()
        ->assertJsonPath('update.installed_version', '5.0.9')
        ->assertJsonPath('update.latest_version', null)
        ->assertJsonPath('update.blocked_reason', 'lookup_failed');

    // Not cached: the next look tries again.
    expect(Cache::get('app-update:moodle:5.0.9'))->toBeNull();
});

it('says when the installed version cannot be read', function () {
    $application = updateApp('moodle');

    $this->withHeaders(updateHeaders())->getJson("/api/applications/{$application->id}/update")
        ->assertOk()
        ->assertJsonPath('update.blocked_reason', 'version_unknown');
});

// ---------------------------------------------------------------- start

it('starts an update on the queue and answers 202', function () {
    Queue::fake();
    $application = updateApp('moodle');
    moodleFixture($application);

    $this->withHeaders(updateHeaders())->postJson("/api/applications/{$application->id}/update")
        ->assertStatus(202)
        ->assertJsonPath('update.status', 'pending')
        ->assertJsonPath('update.from_version', '5.0.9')
        ->assertJsonPath('update.to_version', '5.0.11');

    Queue::assertPushed(UpdateApplication::class, fn ($job) => $job->applicationId === $application->id);
    expect(ActivityLog::where('action', 'update_started')->exists())->toBeTrue();
});

it('refuses a second update while one runs (409)', function () {
    Queue::fake();
    $application = updateApp('moodle');
    moodleFixture($application);
    pendingUpdate($application, '5.0.9', '5.0.11');

    $this->withHeaders(updateHeaders())->postJson("/api/applications/{$application->id}/update")->assertStatus(409);
    Queue::assertNothingPushed();
});

it('releases a pending update whose job never started after an hour', function () {
    Queue::fake();
    $application = updateApp('moodle');
    moodleFixture($application);
    $stuck = pendingUpdate($application, '5.0.9', '5.0.11');
    $stuck->forceFill(['created_at' => now()->subHours(2)])->save();

    $this->withHeaders(updateHeaders())->postJson("/api/applications/{$application->id}/update")->assertStatus(202);
    expect($stuck->fresh()->status)->toBe(ApplicationUpdateStatus::Failed)->and($stuck->fresh()->reason)->toBe('abandoned');
});

it('refuses with a readable reason when there is nothing to update (422)', function (string $case) {
    Queue::fake();
    $application = updateApp($case === 'unsupported' ? 'php' : 'moodle');
    moodleFixture($application);
    if ($case === 'up_to_date') {
        UpdateHost::$updated = true;
    }

    $response = $this->withHeaders(updateHeaders())->postJson("/api/applications/{$application->id}/update")->assertStatus(422);
    expect($response->json('errors.update.0'))->not->toStartWith('app_update.');
    Queue::assertNothingPushed();
})->with(['up_to_date', 'unsupported']);

it('refuses a disabled site', function () {
    Queue::fake();
    $application = updateApp('moodle');
    moodleFixture($application);
    $application->forceFill(['disabled_at' => now()])->save();

    $this->withHeaders(updateHeaders())->postJson("/api/applications/{$application->id}/update")->assertStatus(422);
});

it('needs application manage to start, view to read', function () {
    Queue::fake();
    $application = updateApp('moodle');
    moodleFixture($application);
    $viewer = User::factory()->create();
    grantPermission($viewer, 'application', view: true, manage: false);

    $this->withHeaders(updateHeaders($viewer))->getJson("/api/applications/{$application->id}/update")->assertOk();
    $this->withHeaders(updateHeaders($viewer))->postJson("/api/applications/{$application->id}/update")->assertForbidden();
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- the job, per app

it('updates Moodle: maintenance on, new code of its branch, upgrade, maintenance off', function () {
    $application = updateApp('moodle');
    moodleFixture($application);
    UpdateHost::$finalStep = 'admin/cli/upgrade.php';

    $update = runUpdateJob(pendingUpdate($application, '5.0.9', '5.0.11'));

    expect($update->status)->toBe(ApplicationUpdateStatus::Completed)
        ->and($update->to_version)->toBe('5.0.11')
        ->and(UpdateHost::ran('https://packaging.moodle.org/stable500/moodle-5.0.11.tgz'))->toBeTrue()
        ->and(UpdateHost::ran('admin/cli/maintenance.php --enable'))->toBeTrue()
        ->and(UpdateHost::ran('admin/cli/maintenance.php --disable'))->toBeTrue();

    // As the site's user, on the site's PHP — not the server default v7 used.
    $upgrade = UpdateHost::run('admin/cli/upgrade.php');
    expect(array_slice($upgrade['command'], 0, 3))->toBe(['runuser', '-u', 'siteuser'])
        ->and(implode(' ', $upgrade['command']))->toContain('8.3')
        ->and($upgrade['path'])->toBe($application->documentRoot());

    // config.php is never touched: the package does not carry one.
    expect(collect(UpdateHost::$runs)->contains(fn ($r) => in_array('rm', $r['command'], true) && str_contains(implode(' ', $r['command']), 'config.php')))->toBeFalse();
    expect(ActivityLog::where('action', 'updated')->exists())->toBeTrue();
});

it('turns Moodle maintenance off even when the upgrade fails, and records the step', function () {
    $application = updateApp('moodle');
    moodleFixture($application);
    UpdateHost::$failOn = 'admin/cli/upgrade.php';

    $update = runUpdateJob(pendingUpdate($application, '5.0.9', '5.0.11'));

    expect($update->status)->toBe(ApplicationUpdateStatus::Failed)
        ->and($update->step)->toBe('upgrade')
        ->and($update->reason)->toBe('step_failed')
        ->and($update->reference)->not->toBeNull()
        ->and(UpdateHost::ran('admin/cli/maintenance.php --disable'))->toBeTrue();
});

it('fails an update whose files still carry the old version', function () {
    $application = updateApp('moodle');
    moodleFixture($application);

    $update = runUpdateJob(pendingUpdate($application, '5.0.9', '5.0.11'));

    expect($update->status)->toBe(ApplicationUpdateStatus::Failed)->and($update->reason)->toBe('version_unchanged');
});

it('updates Mautic with its release package, migrations and cache clear (no mautic:update)', function () {
    $application = updateApp('mautic');
    UpdateHost::$files[$application->documentRoot().'/app/release_metadata.json'] = ['{"version":"7.2.1"}', '{"version":"7.3.0"}'];
    UpdateHost::$finalStep = 'mautic:cache:clear';
    Http::fake(['api.github.com/repos/mautic/mautic/releases*' => Http::response([
        ['tag_name' => '8.0.0', 'draft' => false, 'prerelease' => false, 'assets' => [['browser_download_url' => 'https://github.com/m/8.0.0.zip']]],
        ['tag_name' => '7.3.0', 'draft' => false, 'prerelease' => false, 'assets' => [
            ['browser_download_url' => 'https://github.com/m/7.3.0-update.zip'],
            ['browser_download_url' => 'https://github.com/m/7.3.0.zip'],
        ]],
        ['tag_name' => '7.4.0-rc1', 'draft' => false, 'prerelease' => true, 'assets' => []],
    ])]);

    expect(app(ApplicationUpdates::class)->status($application)['latest_version'])->toBe('7.3.0');

    $update = runUpdateJob(pendingUpdate($application, '7.2.1', '7.3.0'));

    expect($update->status)->toBe(ApplicationUpdateStatus::Completed)
        ->and(UpdateHost::ran('https://github.com/m/7.3.0.zip'))->toBeTrue()
        ->and(UpdateHost::ran('-update.zip'))->toBeFalse()
        ->and(UpdateHost::ran('doctrine:migrations:migrate --no-interaction --allow-no-migration'))->toBeTrue()
        ->and(UpdateHost::ran('mautic:update'))->toBeFalse()
        ->and(UpdateHost::ran('config/local.php'))->toBeFalse();
});

it('updates Statamic with composer update inside its constraint, on the site PHP, in the project root', function () {
    $application = updateApp('statamic', '/public');
    UpdateHost::$files[$application->codePath().'/vendor/composer/installed.json'] = [
        '{"packages":[{"name":"statamic/cms","version":"v6.34.1"}]}',
        '{"packages":[{"name":"statamic/cms","version":"v6.35.0"}]}',
    ];
    UpdateHost::$finalStep = 'update statamic/cms';
    Http::fake(['repo.packagist.org/p2/statamic/cms.json' => Http::response(['packages' => ['statamic/cms' => [
        ['version' => 'v7.0.0'], ['version' => 'v6.35.0'], ['version' => 'v6.36.0-beta.1'], ['version' => 'v6.34.1'],
    ]]])]);

    expect(app(ApplicationUpdates::class)->status($application))
        ->latest_version->toBe('6.35.0')
        ->newer_major->toBe('7.0.0');

    $update = runUpdateJob(pendingUpdate($application, '6.34.1', '6.35.0'));
    $composer = UpdateHost::run('update statamic/cms');

    expect($update->status)->toBe(ApplicationUpdateStatus::Completed)
        ->and($composer['path'])->toBe($application->codePath())
        ->and(implode(' ', $composer['command']))->toContain('--with-all-dependencies')
        ->and(implode(' ', $composer['command']))->toContain('php-shims/8.3')
        ->and(UpdateHost::ran('please stache:refresh'))->toBeTrue()
        ->and(UpdateHost::ran('statamic:updates:run'))->toBeFalse();
});

it('updates Craft with Composer pinned to the target (no licence call), then craft up', function () {
    $application = updateApp('craftcms', '/web');
    UpdateHost::$files[$application->codePath().'/vendor/composer/installed.json'] = [
        '{"packages":[{"name":"craftcms/cms","version":"5.11.3"}]}',
        '{"packages":[{"name":"craftcms/cms","version":"5.12.0"}]}',
    ];
    UpdateHost::$finalStep = 'update craftcms/cms';
    Http::fake(['repo.packagist.org/p2/craftcms/cms.json' => Http::response(['packages' => ['craftcms/cms' => [
        ['version' => '6.0.0'], ['version' => '6.0.0-alpha.20'], ['version' => '5.12.0'], ['version' => '5.11.3'],
    ]]])]);

    expect(app(ApplicationUpdates::class)->status($application)['latest_version'])->toBe('5.12.0');

    $update = runUpdateJob(pendingUpdate($application, '5.11.3', '5.12.0'));
    $composer = UpdateHost::run('update craftcms/cms');

    expect($update->status)->toBe(ApplicationUpdateStatus::Completed)
        ->and($composer['command'])->toContain('--with', 'craftcms/cms:5.12.0', '--with-all-dependencies')
        ->and($composer['path'])->toBe($application->codePath())
        ->and(implode(' ', $composer['command']))->toContain('php-shims/8.3')
        ->and(UpdateHost::ran('craft up --interactive=0'))->toBeTrue()
        ->and(UpdateHost::ran('craft update'))->toBeFalse();
});

it('updates Uptime Kuma to the release tag, rebuilds and restarts its service', function () {
    $application = updateApp('uptimekuma', '/', null);
    UpdateHost::$files[$application->documentRoot().'/package.json'] = ['{"version":"2.5.5"}', '{"version":"2.5.6"}'];
    UpdateHost::$finalStep = 'npm run setup';
    Http::fake(['api.github.com/repos/louislam/uptime-kuma/releases*' => Http::response([
        ['tag_name' => '2.5.6', 'draft' => false, 'prerelease' => false],
        ['tag_name' => '1.23.17', 'draft' => false, 'prerelease' => false],
        ['tag_name' => '3.0.0-beta.1', 'draft' => false, 'prerelease' => true],
    ])]);

    expect(app(ApplicationUpdates::class)->status($application)['latest_version'])->toBe('2.5.6');

    $update = runUpdateJob(pendingUpdate($application, '2.5.5', '2.5.6'));

    expect($update->status)->toBe(ApplicationUpdateStatus::Completed)
        ->and(UpdateHost::ran('git fetch --depth 1 origin refs/tags/2.5.6:refs/tags/2.5.6'))->toBeTrue()
        ->and(UpdateHost::ran('git checkout --force 2.5.6'))->toBeTrue()
        ->and(UpdateHost::ran('systemctl restart'))->toBeTrue()
        ->and(UpdateHost::ran('pm2'))->toBeFalse();
});

it('updates phpMyAdmin from the versioned release, keeping config.inc.php', function () {
    $application = updateApp('phpmyadmin');
    UpdateHost::$files[$application->documentRoot().'/package.json'] = ['{"version":"5.2.2"}', '{"version":"5.2.3"}'];
    UpdateHost::$finalStep = 'tar';
    Http::fake(['www.phpmyadmin.net/home_page/version.json' => Http::response(['version' => '5.2.3', 'releases' => [
        ['version' => '5.2.3'], ['version' => '4.9.11'],
    ]])]);

    $update = runUpdateJob(pendingUpdate($application, '5.2.2', '5.2.3'));

    expect($update->status)->toBe(ApplicationUpdateStatus::Completed)
        ->and(UpdateHost::ran('https://files.phpmyadmin.net/phpMyAdmin/5.2.3/phpMyAdmin-5.2.3-all-languages.tar.gz'))->toBeTrue()
        ->and(UpdateHost::ran('config.inc.php'))->toBeFalse();
});

it('updates Adminer by replacing adminer.php alone, keeping the panel\'s wrapper', function () {
    $application = updateApp('adminer');
    UpdateHost::$files[$application->documentRoot().'/adminer.php'] = ["<?php\nconst\nVERSION=\"6.1.1\";", "<?php\nconst\nVERSION=\"6.1.2\";"];
    UpdateHost::$finalStep = 'cp -r';
    Http::fake(['api.github.com/repos/vrana/adminer/releases*' => Http::response([
        ['tag_name' => 'v6.1.2', 'draft' => false, 'prerelease' => false],
        ['tag_name' => 'v5.4.1', 'draft' => false, 'prerelease' => false],
    ])]);

    $update = runUpdateJob(pendingUpdate($application, '6.1.1', '6.1.2'));

    // The version is read back from the compiled file's own `const VERSION`,
    // which the release build splits over two lines.
    expect($update->status)->toBe(ApplicationUpdateStatus::Completed)
        ->and(UpdateHost::ran('https://github.com/vrana/adminer/releases/download/v6.1.2/adminer-6.1.2.php'))->toBeTrue()
        ->and(UpdateHost::ran('index.php'))->toBeFalse()
        ->and(UpdateHost::ran('sso.php'))->toBeFalse();
});

it('marks an update the worker abandoned as failed instead of running forever', function () {
    $application = updateApp('moodle');
    $update = pendingUpdate($application, '5.0.9', '5.0.11');

    (new UpdateApplication($update->id, $application->id))->failed(new RuntimeException('timeout'));

    expect($update->fresh()->status)->toBe(ApplicationUpdateStatus::Failed)->and($update->fresh()->reason)->toBe('worker');
});

it('lists the recent updates with readable status and reason', function () {
    $application = updateApp('moodle');
    moodleFixture($application);
    pendingUpdate($application, '5.0.8', '5.0.9')->forceFill(['status' => ApplicationUpdateStatus::Failed, 'reason' => 'script_git_auth'])->save();

    $this->withHeaders(updateHeaders())->getJson("/api/applications/{$application->id}/update")
        ->assertOk()
        ->assertJsonPath('history.0.status', 'failed')
        ->assertJsonPath('history.0.reason_title', __('app_update.reasons.step_failed'));
});

it('creates and drops its table', function () {
    $migration = require database_path('migrations/2026_10_13_100000_create_application_updates_table.php');

    $migration->down();
    expect(Schema::hasTable('application_updates'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('application_updates'))->toBeTrue();
});

/*
 * n8n and Node-RED (v7's update-n8n / update-node-red). Installed from npm
 * into the site, so the version is read from the site's node_modules and the
 * update is an npm install there — as the site user, with the site's Node,
 * inside the installed major, never to a pre-release npm tags `next`.
 */
function npmApp(string $type, string $installed, ?string $node = '24.11.0'): Application
{
    $application = updateApp($type, '/', null);
    $application->forceFill(['serving_profile' => 'node', 'node_version' => $node, 'app_port' => 5678])->save();
    $package = $type === 'n8n' ? 'n8n' : 'node-red';
    UpdateHost::$files[$application->documentRoot()."/node_modules/{$package}/package.json"] = [
        json_encode(['name' => $package, 'version' => $installed]), json_encode(['name' => $package, 'version' => 'AFTER']),
    ];

    return $application;
}

/** @param  array<string, string>  $versions  version => engines.node */
function fakePackument(string $package, array $tags, array $versions): void
{
    Http::fake(['registry.npmjs.org/'.$package => Http::response([
        'dist-tags' => $tags,
        'versions' => collect($versions)->map(fn ($range) => ['engines' => ['node' => $range]])->all(),
    ])]);
}

function n8nReady(): void
{
    UpdateHost::$outputs['/rest/settings'] = json_encode(['data' => ['userManagement' => ['showSetupOnFirstLoad' => false]]]);
}

it('offers n8n the release npm calls latest, never the higher-numbered `next`', function () {
    $application = npmApp('n8n', '2.40.1');
    fakePackument('n8n', ['latest' => '2.43.6', 'stable' => '2.43.6', 'next' => '2.44.2', 'beta' => '2.44.2'], [
        '2.40.1' => '>=24.0.0', '2.43.6' => '>=24.0.0', '2.44.2' => '>=24.0.0', '1.123.81' => '>=20.19 <= 24.x',
    ]);

    $status = app(ApplicationUpdates::class)->status($application);

    expect($status['installed_version'])->toBe('2.40.1')
        ->and($status['latest_version'])->toBe('2.43.6')
        ->and($status['update_available'])->toBeTrue();
});

it('keeps Node-RED on its major — the maintenance tag — and only reports the next major', function () {
    $application = npmApp('nodered', '4.1.10');
    fakePackument('node-red', ['latest' => '5.0.8', 'v4-maintenance' => '4.1.16', 'next' => '5.0.9'], [
        '4.1.10' => '>=18.5', '4.1.16' => '>=18.5', '4.1.17' => '>=18.5', '5.0.8' => '>=22.9', '5.0.9' => '>=22.9',
    ]);

    $status = app(ApplicationUpdates::class)->status($application);

    expect($status['latest_version'])->toBe('4.1.16')
        ->and($status['newer_major'])->toBe('5.0.8');
});

it('falls back to the highest release of a line no tag covers (n8n 1.x)', function () {
    $application = npmApp('n8n', '1.120.0', '22.11.0');
    fakePackument('n8n', ['latest' => '2.43.6', 'next' => '2.44.2'], [
        '1.120.0' => '>=20.19', '1.123.81' => '>=20.19', '2.43.6' => '>=24.0.0',
    ]);

    expect(app(ApplicationUpdates::class)->status($application)['latest_version'])->toBe('1.123.81');
});

it('updates n8n with npm in the site as its user, restarts it, waits until it is really up, and leaves .env alone', function () {
    $application = npmApp('n8n', '2.40.1');
    fakePackument('n8n', ['latest' => '2.43.6'], ['2.40.1' => '>=24.0.0', '2.43.6' => '>=24.0.0']);
    UpdateHost::$files[$application->documentRoot().'/node_modules/n8n/package.json'][1] = '{"version":"2.43.6"}';
    UpdateHost::$finalStep = 'npm install --omit=dev --no-audit --no-fund n8n@2.43.6';
    n8nReady();

    $update = runUpdateJob(pendingUpdate($application, '2.40.1', '2.43.6'));
    $npm = UpdateHost::run('n8n@2.43.6');
    $root = $application->documentRoot();

    expect($update->status)->toBe(ApplicationUpdateStatus::Completed)
        ->and($npm['command'])->toContain('runuser', 'siteuser')
        ->and(implode(' ', $npm['command']))->toContain('PATH=')
        ->and($npm['path'])->toBe($root)
        ->and(UpdateHost::ran("mv -- {$root}/node_modules {$root}/.node_modules.pre-update"))->toBeTrue()
        ->and(UpdateHost::ran('systemctl restart'))->toBeTrue()
        ->and(UpdateHost::ran('/rest/settings'))->toBeTrue()
        ->and(UpdateHost::ran("rm -rf -- {$root}/.node_modules.pre-update"))->toBeTrue()
        // The encryption key lives in .env; the update must never write it.
        ->and(collect(UpdateHost::$runs)->contains(fn ($r) => in_array("{$root}/.env", $r['command'], true) && $r['command'][0] !== 'cat'))->toBeFalse()
        ->and(UpdateHost::ran('-g'))->toBeFalse();
});

it('refuses a release the site\'s Node cannot run, before anything is moved', function () {
    $application = npmApp('nodered', '4.1.10', '20.18.0');
    fakePackument('node-red', ['latest' => '4.1.16'], ['4.1.10' => '>=18.5', '4.1.16' => '>=22.9']);

    $update = runUpdateJob(pendingUpdate($application, '4.1.10', '4.1.16'));

    expect($update->status)->toBe(ApplicationUpdateStatus::Failed)
        ->and($update->reason)->toBe('node_unsupported')
        ->and(UpdateHost::ran('mv'))->toBeFalse()
        ->and(UpdateHost::ran('npm install'))->toBeFalse();
});

it('puts the previous node_modules back when npm fails, and says which step', function () {
    $application = npmApp('nodered', '4.1.10');
    fakePackument('node-red', ['latest' => '4.1.16'], ['4.1.10' => '>=18.5', '4.1.16' => '>=18.5']);
    UpdateHost::$failOn = 'node-red@4.1.16';
    $root = $application->documentRoot();

    $update = runUpdateJob(pendingUpdate($application, '4.1.10', '4.1.16'));

    expect($update->status)->toBe(ApplicationUpdateStatus::Failed)
        ->and($update->step)->toBe('install_app')
        ->and(UpdateHost::ran("mv -- {$root}/.node_modules.pre-update {$root}/node_modules"))->toBeTrue()
        ->and(UpdateHost::ran("mv -- {$root}/.package.json.pre-update {$root}/package.json"))->toBeTrue();
});

it('puts n8n back when the new version never comes up', function () {
    config(['server.installers.n8n.ready_attempts' => 1]);
    $application = npmApp('n8n', '2.40.1');
    fakePackument('n8n', ['latest' => '2.43.6'], ['2.40.1' => '>=24.0.0', '2.43.6' => '>=24.0.0']);
    UpdateHost::$failOn = '/rest/settings';
    $root = $application->documentRoot();

    $update = runUpdateJob(pendingUpdate($application, '2.40.1', '2.43.6'));

    expect($update->status)->toBe(ApplicationUpdateStatus::Failed)
        ->and($update->reason)->toBe('app_not_ready')
        ->and(UpdateHost::ran("mv -- {$root}/.node_modules.pre-update {$root}/node_modules"))->toBeTrue();
});
