<?php

use App\Jobs\RunAddonCommand;
use App\Models\ActivityLog;
use App\Models\AddonRun;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Addons\WpToolkit;
use App\Services\Server\Applications\ApplicationArtifacts;
use App\Services\Server\CentralTokenManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
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
        $GLOBALS['addonRan'][] = ['command' => $command, 'cwd' => $process->path];
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
        ->assertJsonPath('message', 'This site is not registered with Log Monitoring Suite yet.');
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
