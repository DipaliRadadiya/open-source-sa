<?php

use App\Http\Controllers\API\Server\Addons\InsightsAddonController;
use App\Jobs\RunAddonCommand;
use App\Models\ActivityLog;
use App\Models\AddonRun;
use App\Models\Application;
use App\Models\ApplicationRedisAccount;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Addons\SiteRedisAccount;
use App\Services\Addons\WpToolkit;
use App\Services\Server\Applications\ApplicationArtifacts;
use App\Services\Server\CentralTokenManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * The paid-addon routes Central calls (routes/api/server/central-addons.php).
 *
 * The binaries are faked at the process boundary: what is asserted is the
 * argument list the panel hands them and what it makes of each kind of
 * answer, which is the whole of the panel's side of the contract.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = app(CentralTokenManager::class)->enable()['central_token'];

    $dir = sys_get_temp_dir().'/sv-addons-'.getmypid();
    @mkdir($dir);
    foreach (['wp-toolkit', 'insighthub-toolkit'] as $bin) {
        file_put_contents("{$dir}/{$bin}", "#!/bin/sh\n");
        chmod("{$dir}/{$bin}", 0755);
    }
    $this->binDir = $dir;

    config([
        'server.addons.wp_toolkit.binary' => "{$dir}/wp-toolkit",
        'server.addons.insighthub.binary' => "{$dir}/insighthub-toolkit",
        'server.addons.insighthub.workdir' => '/etc/insighthub-toolkit',
        'server.site_rules_root' => '/etc/panel-site-rules',
    ]);

    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->site = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active',
        'web_root' => '/', 'php_version' => '8.4',
    ]);

    $GLOBALS['addonRan'] = [];
});

afterEach(function () {
    @unlink("{$this->binDir}/wp-toolkit");
    @unlink("{$this->binDir}/insighthub-toolkit");
    @rmdir($this->binDir);
});

/** @return array<string, string> */
function addonHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Fake every process: record the command, answer with what $answer returns
 * for it — [stdout, stderr, exit].
 */
function fakeAddon(callable $answer): void
{
    Process::fake(function (PendingProcess $process) use ($answer) {
        $command = (array) $process->command;
        $GLOBALS['addonRan'][] = ['command' => $command, 'cwd' => $process->path, 'input' => $process->input, 'env' => $process->environment];
        [$out, $err, $exit] = $answer($command) + ['', '', 0];

        return Process::result(output: $out, errorOutput: $err, exitCode: $exit);
    });
}

function wpUrl(string $path = ''): string
{
    return '/api/central/addons/applications/'.test()->site->id.'/wordpress'.$path;
}

function lastAddonCommand(): array
{
    return end($GLOBALS['addonRan'])['command'];
}

// ── who may call ─────────────────────────────────────────────────────────────

it('does not exist for an administrator', function () {
    fakeAddon(fn () => ['{"status":"success"}']);

    $this->actingAs($this->admin)->getJson('/api/central/addons')->assertNotFound();
    $this->actingAs($this->admin)->getJson(wpUrl('/plugins'))->assertNotFound();

    expect($GLOBALS['addonRan'])->toBe([]);
});

it('asks anyone without credentials to authenticate', function () {
    $this->getJson('/api/central/addons')->assertUnauthorized();
});

it('is reachable with the central token', function () {
    fakeAddon(fn () => ['wp-toolkit version 1.2']);

    $this->withHeaders(addonHeaders($this->token))->getJson('/api/central/addons')
        ->assertOk()
        ->assertJsonPath('addons.0.name', 'wp-toolkit')
        ->assertJsonPath('addons.0.installed', true)
        ->assertJsonPath('addons.0.version', 'wp-toolkit version 1.2')
        // Its current product name; it launched as InsightHub.
        ->assertJsonPath('addons.1.label', 'Log Monitoring Suite');
});

// ── running a command ────────────────────────────────────────────────────────

it('passes the toolkit answer through unchanged, run as the site owner on its root', function () {
    $answer = '{"status":"success","message":"Plugins listed successfully","plugins":[{"name":"akismet","status":"active"}]}';
    fakeAddon(fn () => [$answer]);

    $this->withHeaders(addonHeaders($this->token))->getJson(wpUrl('/plugins'))
        ->assertOk()
        ->assertExactJson(json_decode($answer, true));

    $command = lastAddonCommand();
    expect(array_slice($command, 0, 7))->toBe([
        "{$this->binDir}/wp-toolkit", 'plugin', 'list',
        '--system-user', 'siteowner', '--path', $this->site->documentRoot(),
    ]);
});

it('only serves WordPress sites', function () {
    $this->site->forceFill(['site_type' => 'php'])->save();
    fakeAddon(fn () => ['{"status":"success"}']);

    $this->withHeaders(addonHeaders($this->token))->getJson(wpUrl('/plugins'))->assertNotFound();
    expect($GLOBALS['addonRan'])->toBe([]);
});

it('maps every kind of answer to its status and code', function (array $process, int $status, string $code) {
    fakeAddon(fn () => $process);

    $this->withHeaders(addonHeaders($this->token))->getJson(wpUrl('/plugins'))
        ->assertStatus($status)
        ->assertJsonPath('code', $code);
})->with([
    'not purchased' => [['', '{"status":"error","code":"licence_required","message":"no purchase"}', 1], 403, 'addon_licence_required'],
    'wp-cli refused' => [['', '{"status":"error","message":"wp plugin list: Error: not a WordPress install"}', 1], 422, 'addon_command_failed'],
    'not JSON' => [['PHP Fatal error: oops', '', 255], 502, 'addon_bad_output'],
]);

it('says the addon is not installed, and runs nothing, when the binary is absent', function () {
    unlink("{$this->binDir}/wp-toolkit");
    fakeAddon(fn () => ['{"status":"success"}']);

    $this->withHeaders(addonHeaders($this->token))->getJson(wpUrl('/plugins'))
        ->assertNotFound()
        ->assertJsonPath('code', 'addon_not_installed');

    expect($GLOBALS['addonRan'])->toBe([]);
});

it('treats a checksum mismatch as the answer, not a failure', function () {
    Queue::fake();
    $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/core/verify-checksums'))->assertStatus(202);

    fakeAddon(fn () => ['{"status":"success","message":"Core checksums do not match","verified":false}', '', 1]);
    (new RunAddonCommand(AddonRun::first()->id))->handle(app(WpToolkit::class));

    expect(AddonRun::first())
        ->status->toBe(AddonRun::SUCCEEDED)
        ->http_status->toBe(200)
        ->result->toMatchArray(['verified' => false]);
});

it('queues what can outlast a request and answers the run to poll', function () {
    Queue::fake();
    fakeAddon(fn () => ['{"status":"success","message":"Plugin installed successfully","plugin":"akismet"}']);

    $response = $this->withHeaders(addonHeaders($this->token))
        ->postJson(wpUrl('/plugins'), ['plugin' => 'akismet', 'activate' => true])
        ->assertStatus(202)
        ->assertJsonPath('data.status', AddonRun::QUEUED)
        ->assertJsonPath('data.command', 'plugins.install');

    Queue::assertPushed(RunAddonCommand::class);
    expect($GLOBALS['addonRan'])->toBe([]);

    $run = AddonRun::findOrFail($response->json('data.id'));
    expect(array_slice($run->arguments, 0, 4))->toBe(['plugin', 'install', 'akismet', '--activate']);

    (new RunAddonCommand($run->id))->handle(app(WpToolkit::class));

    $this->withHeaders(addonHeaders($this->token))->getJson('/api/central/addons/runs/'.$run->id)
        ->assertOk()
        ->assertJsonPath('data.status', AddonRun::SUCCEEDED)
        ->assertJsonPath('data.result.plugin', 'akismet');
});

it('records a failed queued run with the same body a direct call returns', function () {
    Queue::fake();
    $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/plugins/update-all'))->assertStatus(202);

    fakeAddon(fn () => ['', '{"status":"error","code":"licence_required","message":"no"}', 1]);
    (new RunAddonCommand(AddonRun::first()->id))->handle(app(WpToolkit::class));

    expect(AddonRun::first())
        ->status->toBe(AddonRun::FAILED)
        ->http_status->toBe(403)
        ->result->toMatchArray(['code' => 'addon_licence_required']);
});

// ── input ────────────────────────────────────────────────────────────────────

it('refuses what the toolkit could read as a flag, before anything runs', function () {
    Queue::fake();
    fakeAddon(fn () => ['{"status":"success"}']);

    $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/plugins'), ['plugin' => '--path=/etc'])->assertUnprocessable();
    $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/search-replace'), ['search' => '--url=x', 'replace' => 'y'])->assertUnprocessable();
    $this->withHeaders(addonHeaders($this->token))->deleteJson(wpUrl('/plugins/..%2Fx'))->assertNotFound();

    expect($GLOBALS['addonRan'])->toBe([])->and(AddonRun::count())->toBe(0);
});

it('hands search-replace its values after --, with the site target before it', function () {
    Queue::fake();

    $this->withHeaders(addonHeaders($this->token))
        ->postJson(wpUrl('/search-replace'), ['search' => '-old; $(id)', 'replace' => 'new', 'dry_run' => true])
        ->assertStatus(202);

    $arguments = AddonRun::first()->arguments;
    $separator = array_search('--', $arguments, true);

    expect($arguments[0])->toBe('search-replace')
        ->and($arguments)->toContain('--dry-run')
        ->and(array_slice($arguments, $separator))->toBe(['--', '-old; $(id)', 'new'])
        ->and(array_search('--system-user', $arguments, true))->toBeLessThan($separator);
});

it('changes only the settings that were sent', function () {
    fakeAddon(fn () => ['{"status":"success","message":"Settings updated successfully"}']);

    $this->withHeaders(addonHeaders($this->token))
        ->putJson(wpUrl('/settings'), ['timezone' => 'Europe/Berlin', 'permalink_structure' => ''])
        ->assertOk();

    $command = lastAddonCommand();
    expect(array_slice($command, 1, 4))->toBe(['settings', 'set', '--timezone=Europe/Berlin', '--permalink-structure='])
        ->and(implode(' ', $command))->not->toContain('--date-format');
});

it('points the security commands at the site rules directory, not at WordPress', function () {
    fakeAddon(fn () => ['{"status":"success","blocked":true}']);

    $this->withHeaders(addonHeaders($this->token))->putJson(wpUrl('/security/uploads-php'), ['blocked' => true])->assertOk();

    $command = lastAddonCommand();
    expect(array_slice($command, 1, 3))->toBe(['security', 'uploads-php', 'block'])
        ->and($command)->toContain('--rules-dir', '/etc/panel-site-rules/blog')
        ->and($command)->not->toContain('--system-user');
});

it('logs what changed a site and nothing that only read it', function () {
    fakeAddon(fn () => ['{"status":"success"}']);

    $this->withHeaders(addonHeaders($this->token))->getJson(wpUrl('/settings'))->assertOk();
    expect(ActivityLog::where('action', 'addon_command')->count())->toBe(0);

    $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/cache/flush'))->assertOk();
    expect(ActivityLog::where('action', 'addon_command')->first()->properties)
        ->toMatchArray(['command' => 'cache.flush', 'addon' => 'WP Toolkit']);
});

// ── InsightHub ───────────────────────────────────────────────────────────────

it('asks for registration before any report', function () {
    fakeAddon(fn () => ['{"status":"success"}']);

    $this->withHeaders(addonHeaders($this->token))
        ->getJson("/api/central/addons/applications/{$this->site->id}/log-monitoring/bandwidth/summary")
        ->assertStatus(409)
        ->assertJsonPath('code', 'addon_site_not_registered')
        ->assertJsonPath('message', 'This application is not registered with Log Monitoring Suite yet.');
});

it('registers a site under the panel key, from its own working directory', function () {
    fakeAddon(fn () => ['{"status":"success","application":{"id":7}}']);

    $this->withHeaders(addonHeaders($this->token))
        ->postJson("/api/central/addons/applications/{$this->site->id}/log-monitoring/register")
        ->assertOk();

    $call = end($GLOBALS['addonRan']);
    expect($this->site->fresh()->insighthub_id)->toBe(7)
        ->and($call['cwd'])->toBe('/etc/insighthub-toolkit')
        ->and($call['command'])->toContain('applications:add', '--name=blog', '--system-user=siteowner', "--key=v8-{$this->site->id}");
});

it('adopts its own earlier registration instead of failing on a retry', function () {
    fakeAddon(fn () => ['', '{"status":"error","code":"conflict","field":"key","application_id":9,"message":"taken"}', 1]);

    $this->withHeaders(addonHeaders($this->token))
        ->postJson("/api/central/addons/applications/{$this->site->id}/log-monitoring/register")
        ->assertOk();

    expect($this->site->fresh()->insighthub_id)->toBe(9);
});

it('reports a name held by another site as a conflict', function () {
    fakeAddon(fn () => ['', '{"status":"error","code":"conflict","field":"domain","application_id":3,"message":"taken"}', 1]);

    $this->withHeaders(addonHeaders($this->token))
        ->postJson("/api/central/addons/applications/{$this->site->id}/log-monitoring/register")
        ->assertUnprocessable()
        ->assertJsonPath('addon.field', 'domain');

    expect($this->site->fresh()->insighthub_id)->toBeNull();
});

it('answers a repeated report from the cache', function () {
    Cache::flush();
    $this->site->forceFill(['insighthub_id' => 7])->save();
    fakeAddon(fn () => ['{"status":"success","data":{"total_bandwidth":10}}']);

    $url = "/api/central/addons/applications/{$this->site->id}/log-monitoring/bandwidth/top-ips?limit=5";
    $this->withHeaders(addonHeaders($this->token))->getJson($url)->assertOk()->assertJsonPath('data.total_bandwidth', 10);
    $this->withHeaders(addonHeaders($this->token))->getJson($url)->assertOk();

    expect($GLOBALS['addonRan'])->toHaveCount(1)
        ->and($GLOBALS['addonRan'][0]['command'])->toBe(["{$this->binDir}/insighthub-toolkit", 'bandwidth:top-ips', '--application=7', '--limit=5']);
});

it('does not cache a failure', function () {
    Cache::flush();
    $this->site->forceFill(['insighthub_id' => 7])->save();
    fakeAddon(fn () => ['', '{"status":"error","code":"licence_required","message":"no"}', 1]);

    $url = "/api/central/addons/applications/{$this->site->id}/log-monitoring/bandwidth/summary";
    $this->withHeaders(addonHeaders($this->token))->getJson($url)->assertForbidden();
    $this->withHeaders(addonHeaders($this->token))->getJson($url)->assertForbidden();

    expect($GLOBALS['addonRan'])->toHaveCount(2);
});

it('knows only the reports the toolkit has', function () {
    $this->site->forceFill(['insighthub_id' => 7])->save();

    $this->withHeaders(addonHeaders($this->token))
        ->getJson("/api/central/addons/applications/{$this->site->id}/log-monitoring/bandwidth/everything")
        ->assertNotFound();
});

it('unregisters a site from InsightHub when the site is deleted', function () {
    $this->site->forceFill(['insighthub_id' => 7])->save();
    fakeAddon(fn () => ['{"status":"success"}']);

    app(ApplicationArtifacts::class)->remove($this->site);

    expect(collect($GLOBALS['addonRan'])->contains(fn ($c) => in_array('applications:remove', $c['command'], true)
        && in_array("--key=v8-{$this->site->id}", $c['command'], true)))->toBeTrue();
});

it('serves every report group the toolkit has, on the paths v7 used', function () {
    Cache::flush();
    $this->site->forceFill(['insighthub_id' => 7])->save();
    fakeAddon(fn () => ['{"status":"success","data":{}}']);

    $base = "/api/central/addons/applications/{$this->site->id}/log-monitoring";
    foreach (['dashboard/stats' => 'dashboard:stats', 'traffic/summary' => 'traffic:summary', 'errors/status-summary' => 'errors:status-summary',
        'bots/bot-traffic-trends' => 'bots:traffic-trends', 'user-agents/os-pie-chart' => 'user-agents:os-pie-chart'] as $path => $command) {
        $this->withHeaders(addonHeaders($this->token))->getJson("{$base}/{$path}")->assertOk();
        expect(lastAddonCommand())->toBe(["{$this->binDir}/insighthub-toolkit", $command, '--application=7']);
    }

    expect(collect(InsightsAddonController::REPORTS)->flatten()->count())->toBe(49);
});

it('requires and checks the extra option two reports take', function () {
    Cache::flush();
    $this->site->forceFill(['insighthub_id' => 7])->save();
    fakeAddon(fn () => ['{"status":"success","data":[]}']);
    $base = "/api/central/addons/applications/{$this->site->id}/log-monitoring";

    $this->withHeaders(addonHeaders($this->token))->getJson("{$base}/traffic/url-and-field-count")->assertUnprocessable()->assertJsonValidationErrors('field');
    $this->withHeaders(addonHeaders($this->token))->getJson("{$base}/traffic/url-and-field-count?field=raw_log")->assertUnprocessable();
    $this->withHeaders(addonHeaders($this->token))->getJson("{$base}/errors/status-code-data?status_code=6xx")->assertUnprocessable();
    expect($GLOBALS['addonRan'])->toBe([]);

    $this->withHeaders(addonHeaders($this->token))->getJson("{$base}/traffic/url-and-field-count?field=method&limit=5")->assertOk();
    expect(lastAddonCommand())->toBe(["{$this->binDir}/insighthub-toolkit", 'traffic:url-and-field-count', '--application=7', '--limit=5', '--field=method']);

    $this->withHeaders(addonHeaders($this->token))->getJson("{$base}/errors/status-code-data?status_code=5xx")->assertOk();
    expect(lastAddonCommand())->toContain('--status-code=5xx');
});

it('passes a limit only to reports that are lists', function () {
    Cache::flush();
    $this->site->forceFill(['insighthub_id' => 7])->save();
    fakeAddon(fn () => ['{"status":"success","data":{}}']);
    $base = "/api/central/addons/applications/{$this->site->id}/log-monitoring";

    $this->withHeaders(addonHeaders($this->token))->getJson("{$base}/dashboard/stats?limit=3")->assertOk();
    expect(lastAddonCommand())->not->toContain('--limit=3');

    $this->withHeaders(addonHeaders($this->token))->getJson("{$base}/dashboard/status-count?limit=3")->assertOk();
    expect(lastAddonCommand())->toContain('--limit=3');
});

// ── blueprint ────────────────────────────────────────────────────────────────

it('queues a blueprint with its script on stdin, encrypted at rest and never echoed', function () {
    Queue::fake();
    $blueprint = [
        'selected_plugins' => [['slug' => 'akismet', 'activate' => true]],
        'custom_theme_plugins' => [['label' => 'Mine', 'link' => 'https://cdn.example/p.zip?key=LINK-SECRET', 'type' => 'custom-plugin']],
        'delete_all_themes' => true, 'timezone' => 'UTC',
        'script' => "wp option update blogdescription 'SCRIPT-SECRET'",
    ];

    $response = $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/blueprint'), $blueprint)
        ->assertStatus(202)->assertJsonPath('data.command', 'blueprint.apply');

    expect($response->getContent())->not->toContain('SCRIPT-SECRET');
    $run = AddonRun::firstOrFail();
    expect(array_slice($run->arguments, 0, 2))->toBe(['blueprint', 'apply'])
        ->and(implode(' ', $run->arguments))->not->toContain('SECRET')
        ->and(DB::table('addon_runs')->value('input'))->not->toContain('SCRIPT-SECRET');

    $sent = json_decode($run->input, true);
    expect($sent['script'])->toBe($blueprint['script'])
        ->and($sent['selected_plugins'][0])->toBe(['slug' => 'akismet', 'activate' => true])
        ->and($sent['delete_all_themes'])->toBeTrue()
        ->and($sent)->not->toHaveKey('language');

    fakeAddon(fn () => ['{"status":"success","completed":true,"steps":[]}']);
    (new RunAddonCommand($run->id))->handle(app(WpToolkit::class));

    $call = end($GLOBALS['addonRan']);
    expect($call['input'])->toBe($run->input)
        ->and(AddonRun::first()->status)->toBe(AddonRun::SUCCEEDED)
        // The script has done its job; it does not outlive the run.
        ->and(DB::table('addon_runs')->value('input'))->toBeNull();
});

it('refuses a blueprint with values the toolkit could misread', function (array $bad) {
    Queue::fake();
    $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/blueprint'), $bad)->assertUnprocessable();
    expect(AddonRun::count())->toBe(0);
})->with([
    'slug as a flag' => [['selected_plugins' => [['slug' => '--path=/etc']]]],
    'http link' => [['custom_theme_plugins' => [['link' => 'http://x/p.zip', 'type' => 'custom-plugin']]]],
    'unknown custom type' => [['custom_theme_plugins' => [['link' => 'https://x/p.zip', 'type' => 'mu-plugin']]]],
    'script too large' => [['script' => str_repeat('x', 65537)]],
]);

// ── Object Cache Pro ─────────────────────────────────────────────────────────

/** A Redis that answers like 7.x with a password set; records every call. */
function fakeRedisAndToolkit(string $version = '7.2.4', array $toolkitAnswer = ['{"status":"success","object_cache_pro":{"active":true}}']): void
{
    config(['database.redis.default.password' => 'ADMIN-PW', 'server.redis_cli' => '/usr/bin/redis-cli']);
    fakeAddon(function (array $command) use ($version, $toolkitAnswer) {
        if (str_ends_with($command[0], 'redis-cli')) {
            return match (true) {
                in_array('INFO', $command, true) => ["# Server\r\nredis_version:{$version}\r\n"],
                in_array('aclfile', $command, true) => ["aclfile\n\n"],
                default => ["OK\n"],
            };
        }

        return $toolkitAnswer;
    });
}

function redisCalls(): array
{
    return array_values(array_filter($GLOBALS['addonRan'], fn ($c) => str_ends_with($c['command'][0], 'redis-cli')));
}

it('gives the site its own Redis login, limited to its own keys, and queues the install', function () {
    Queue::fake();
    fakeRedisAndToolkit();

    $this->withHeaders(addonHeaders($this->token))
        ->postJson(wpUrl('/object-cache-pro'), ['token' => 'abc123def456', 'plugin_url' => 'https://objectcache.pro/dl?k=1', 'prefetch' => true])
        ->assertStatus(202)->assertJsonPath('data.command', 'object-cache-pro.enable');

    $account = ApplicationRedisAccount::firstOrFail();
    $setuser = collect(redisCalls())->first(fn ($c) => in_array('SETUSER', $c['command'], true))['command'];

    expect($account->username)->toBe("sv_site_{$this->site->id}")
        ->and($setuser)->toContain('reset', 'on', '~sv'.$this->site->id.':*', 'resetchannels', '-@admin', '-@dangerous', '#'.hash('sha256', $account->password))
        // The site's password and the admin password never on a command line.
        ->and(implode(' ', array_merge(...array_column($GLOBALS['addonRan'], 'command'))))->not->toContain($account->password)->not->toContain('ADMIN-PW')
        ->and(redisCalls()[0]['env'])->toBe(['REDISCLI_AUTH' => 'ADMIN-PW'])
        ->and(collect(redisCalls())->contains(fn ($c) => in_array('REWRITE', $c['command'], true)))->toBeTrue();

    $input = json_decode(AddonRun::firstOrFail()->input, true);
    expect($input)->toMatchArray(['token' => 'abc123def456', 'plugin_url' => 'https://objectcache.pro/dl?k=1', 'prefetch' => true,
        'username' => $account->username, 'password' => $account->password, 'prefix' => 'sv'.$this->site->id.':', 'database' => 0]);
});

it('refuses when Redis could not keep sites apart', function (string $version, ?string $adminPassword) {
    Queue::fake();
    fakeRedisAndToolkit($version);
    config(['database.redis.default.password' => $adminPassword]);

    $this->withHeaders(addonHeaders($this->token))
        ->postJson(wpUrl('/object-cache-pro'), ['token' => 'abc123def456', 'plugin_url' => 'https://objectcache.pro/dl'])
        ->assertStatus(409)->assertJsonPath('code', 'object_cache_redis_unavailable');

    expect(ApplicationRedisAccount::count())->toBe(0)->and(AddonRun::count())->toBe(0);
})->with([
    'Redis 5, no ACLs' => ['5.0.7', 'ADMIN-PW'],
    'no admin password, so anyone is the default user' => ['7.2.4', null],
]);

it('rotates the password without a moment the site cannot log in', function () {
    fakeRedisAndToolkit();
    $account = app(SiteRedisAccount::class)->ensure($this->site);
    $account->settings = ['token' => 'abc123def456', 'plugin_url' => 'https://objectcache.pro/dl'];
    $account->save();
    $old = $account->password;
    $GLOBALS['addonRan'] = [];

    $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/object-cache-pro/rotate'))->assertOk();

    $new = ApplicationRedisAccount::firstOrFail()->password;
    $order = collect($GLOBALS['addonRan'])->map(fn ($c) => str_ends_with($c['command'][0], 'redis-cli')
        ? implode(' ', array_slice($c['command'], 5)) : 'toolkit '.$c['command'][2])->values()->all();
    $configure = collect($GLOBALS['addonRan'])->first(fn ($c) => in_array('configure', $c['command'], true));

    expect($new)->not->toBe($old)
        ->and($order)->toContain("ACL SETUSER sv_site_{$this->site->id} #".hash('sha256', $new))
        ->and(array_search("ACL SETUSER sv_site_{$this->site->id} #".hash('sha256', $new), $order))->toBeLessThan(array_search('toolkit configure', $order))
        ->and(array_search('toolkit configure', $order))->toBeLessThan(array_search("ACL SETUSER sv_site_{$this->site->id} !".hash('sha256', $old), $order))
        ->and(json_decode($configure['input'], true))->toMatchArray(['password' => $new])->not->toHaveKey('plugin_url');
});

it('withdraws the new password and keeps the old one if the site could not take it', function () {
    fakeRedisAndToolkit(toolkitAnswer: ['', '{"status":"error","message":"wp-config.php is not writable"}', 1]);
    config(['database.redis.default.password' => 'ADMIN-PW']);
    $account = ApplicationRedisAccount::create(['application_id' => $this->site->id, 'username' => "sv_site_{$this->site->id}",
        'password' => 'OLD-PASSWORD', 'prefix' => "sv{$this->site->id}:", 'settings' => ['token' => 'abc123def456']]);

    $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/object-cache-pro/rotate'))->assertUnprocessable();

    $setusers = collect(redisCalls())->filter(fn ($c) => in_array('SETUSER', $c['command'], true))->map(fn ($c) => end($c['command']))->values();
    expect(ApplicationRedisAccount::firstOrFail()->password)->toBe('OLD-PASSWORD')
        ->and($setusers->first())->toStartWith('#')
        ->and($setusers->last())->toBe('!'.substr($setusers->first(), 1))
        ->and($setusers)->not->toContain('!'.hash('sha256', 'OLD-PASSWORD'));
});

it('removes the plugin first and the Redis login after, and both with the site', function () {
    fakeRedisAndToolkit();
    app(SiteRedisAccount::class)->ensure($this->site);
    $GLOBALS['addonRan'] = [];

    $this->withHeaders(addonHeaders($this->token))->deleteJson(wpUrl('/object-cache-pro'))->assertOk();

    $order = collect($GLOBALS['addonRan'])->map(fn ($c) => implode(' ', $c['command']))->values();
    $disable = $order->search(fn ($c) => str_contains($c, 'object-cache-pro disable'));
    $deluser = $order->search(fn ($c) => str_contains($c, 'ACL DELUSER'));
    expect($disable)->not->toBeFalse()->and($deluser)->toBeGreaterThan($disable)
        ->and(ApplicationRedisAccount::count())->toBe(0);

    app(SiteRedisAccount::class)->ensure($this->site);
    app(ApplicationArtifacts::class)->remove($this->site);
    expect(ApplicationRedisAccount::count())->toBe(0);
});

it('rotating a site without Object Cache Pro is refused', function () {
    fakeRedisAndToolkit();
    $this->withHeaders(addonHeaders($this->token))->postJson(wpUrl('/object-cache-pro/rotate'))->assertUnprocessable();
    expect(redisCalls())->toBe([]);
});
