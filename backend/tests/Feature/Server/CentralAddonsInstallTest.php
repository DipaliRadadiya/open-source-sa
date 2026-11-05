<?php

use App\Jobs\RunAddonLifecycle;
use App\Models\ActivityLog;
use App\Models\AddonRun;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\CentralTokenManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Installing, updating and removing the addon binaries for Central
 * (POST /central/addons/{addon}/install, DELETE /central/addons/{addon}).
 *
 * Every path the installer touches is pointed into a scratch directory, and
 * the process fake *does* what the file commands say — install, cp, mv, rm,
 * tee, chmod — there and nowhere else. So what is asserted is the state the
 * server is left in, not only the commands that were run: "nothing written"
 * and "rolled back" are checked on disk.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = app(CentralTokenManager::class)->enable()['central_token'];

    $dir = sys_get_temp_dir().'/sv-addon-install-'.getmypid();
    File::deleteDirectory($dir);
    File::ensureDirectoryExists($dir.'/bin');
    File::ensureDirectoryExists($dir.'/units');
    $this->dir = $dir;

    $keys = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keys);

    config([
        'central.url' => 'https://central.test',
        'server.addons.signing_public_key' => base64_encode(sodium_crypto_sign_publickey($keys)),
        'server.addons.require_signature' => true,
        'server.addons.wp_toolkit.binary' => "{$dir}/bin/wp-toolkit",
        'server.addons.log_monitoring_suite.binary' => "{$dir}/bin/log-monitoring-suite",
        'server.addons.log_monitoring_suite.workdir' => "{$dir}/etc-lms",
        'server.addons.log_monitoring_suite.data_dir' => "{$dir}/var-lms",
        'server.addons.log_monitoring_suite.units_dir' => "{$dir}/units",
        'server.addons.log_monitoring_suite.v7' => [
            'service' => 'insighthub.service',
            'unit_files' => ["{$dir}/v7/lib/insighthub.service", "{$dir}/v7/etc/insighthub.service"],
            'directories' => ["{$dir}/v7/insighthub", "{$dir}/v7/log"],
        ],
    ]);

    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->site = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active',
        'web_root' => '/', 'php_version' => '8.4',
    ]);

    $this->binary = "\x7fELF".random_bytes(256);
    $GLOBALS['addonDownloadFaked'] = false;
    fakeAddonDownload($this->binary);
    fakeLifecycle();
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/**
 * Central's download, answering with these bytes from now on. One stub,
 * reading what to answer at request time: a second Http::fake() would only
 * add a stub behind the first.
 */
function fakeAddonDownload(string $bytes): void
{
    $GLOBALS['addonDownload'] = $bytes;

    if (! ($GLOBALS['addonDownloadFaked'] ?? false)) {
        Http::fake(['central.test/*' => fn () => Http::response($GLOBALS['addonDownload'])]);
        $GLOBALS['addonDownloadFaked'] = true;
    }
}

/** An installed binary, as the panel detects one: a file that is executable. */
function placeBinary(string $path, string $bytes): void
{
    file_put_contents($path, $bytes);
    chmod($path, 0755);
}

/**
 * Fake every process, recording it and carrying out the file commands inside
 * the scratch directory. `$answers` overrides by substring of the command
 * line: [stdout, stderr, exit], or a callable returning one (or null to fall
 * through).
 *
 * @param  array<string, array|callable>  $answers
 */
function fakeLifecycle(array $answers = []): void
{
    $GLOBALS['lifecycleRan'] = [];
    $GLOBALS['lifecycleCwd'] = [];
    $dir = test()->dir;

    Process::fake(function (PendingProcess $process) use ($answers, $dir) {
        $command = (array) $process->command;
        if (($command[0] ?? null) === 'sudo') {
            $command = array_slice($command, 2);
        }
        $GLOBALS['lifecycleRan'][] = $command;
        $line = implode(' ', $command);
        $GLOBALS['lifecycleCwd'][$line] = $process->path;

        foreach ($answers as $match => $answer) {
            if (str_contains($line, $match)) {
                $answer = is_callable($answer) ? $answer($command) : $answer;
                if ($answer !== null) {
                    [$out, $err, $exit] = $answer + ['', '', 0];

                    return Process::result(output: $out, errorOutput: $err, exitCode: $exit);
                }
            }
        }

        // Only ever inside the scratch directory.
        $inside = fn (string $path): bool => str_starts_with($path, $dir.'/');
        $last = end($command);

        switch ($command[0]) {
            case 'install':
                if ($command[1] === '-d') {
                    $inside($last) && File::ensureDirectoryExists($last);
                } elseif ($inside($last)) {
                    copy($command[7], $last);
                    chmod($last, 0755);
                }
                break;
            case 'cp':
                $inside($command[3]) && copy($command[2], $command[3]);
                break;
            case 'mv':
                $inside($command[3]) && rename($command[2], $command[3]);
                break;
            case 'rm':
                foreach (array_slice($command, 2) as $path) {
                    if ($inside($path)) {
                        is_dir($path) ? File::deleteDirectory($path) : @unlink($path);
                    }
                }
                break;
            case 'tee':
                $inside($last) && file_put_contents($last, (string) $process->input);
                break;
            case 'chmod':
                $inside($last) && chmod($last, octdec($command[1]));
                break;
            case 'df':
                return Process::result(output: "  Avail\n".(50 * 1073741824)."\n");
            case 'du':
                return Process::result(output: "4096\t{$last}\n");
        }

        if (str_ends_with($command[0], '/log-monitoring-suite') || str_ends_with($command[0], '/wp-toolkit')) {
            return match ($command[1] ?? '') {
                '--version' => Process::result(output: basename($command[0])." version 2.0.0\n"),
                'applications:add' => Process::result(output: '{"status":"success","message":"Application added","application":{"id":41}}'),
                default => Process::result(output: '{"status":"success","message":"ok"}'),
            };
        }

        return Process::result();
    });
}

function ranLifecycle(string $needle): bool
{
    return collect($GLOBALS['lifecycleRan'])->contains(fn (array $c) => str_contains(implode(' ', $c), $needle));
}

/** @return array<string, mixed> */
function installBody(string $bytes, array $overrides = []): array
{
    return array_merge([
        'version' => '2.0.0',
        'url' => 'https://central.test/addons/download/abc',
        'sha256' => hash('sha256', $bytes),
        'signature' => base64_encode(sodium_crypto_sign_detached($bytes, test()->secretKey)),
    ], $overrides);
}

function installAddon(string $addon, array $body)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson("/api/central/addons/{$addon}/install", $body);
}

function removeAddon(string $addon)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->deleteJson("/api/central/addons/{$addon}");
}

/** Nothing of Log Monitoring Suite exists in the scratch directory. */
function expectNoLmsOnDisk(): void
{
    $dir = test()->dir;

    expect(file_exists("{$dir}/bin/log-monitoring-suite"))->toBeFalse()
        ->and(file_exists("{$dir}/etc-lms"))->toBeFalse()
        ->and(file_exists("{$dir}/var-lms"))->toBeFalse()
        ->and(glob("{$dir}/units/*"))->toBe([])
        ->and(glob("{$dir}/bin/*"))->toBe([]);
}

// ── who may call ─────────────────────────────────────────────────────────────

it('does not exist for an administrator', function () {
    $this->actingAs($this->admin)->postJson('/api/central/addons/wp-toolkit/install', installBody($this->binary))->assertNotFound();
    $this->actingAs($this->admin)->deleteJson('/api/central/addons/wp-toolkit')->assertNotFound();

    expect(AddonRun::count())->toBe(0)
        ->and($GLOBALS['lifecycleRan'])->toBe([]);
});

it('answers 404 for an addon that does not exist', function () {
    installAddon('nope', installBody($this->binary))->assertNotFound();
    removeAddon('nope')->assertNotFound();

    expect(AddonRun::count())->toBe(0);
});

// ── installing wp-toolkit ────────────────────────────────────────────────────

it('installs wp-toolkit from Central, verified, and answers 202 with the run', function () {
    $response = installAddon('wp-toolkit', installBody($this->binary))
        ->assertStatus(202)
        ->assertJsonPath('data.addon', 'wp-toolkit')
        ->assertJsonPath('data.command', 'install')
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result.action', 'installed')
        ->assertJsonPath('data.result.version', '2.0.0');

    expect(file_get_contents("{$this->dir}/bin/wp-toolkit"))->toBe($this->binary)
        ->and(ranLifecycle('install -m 0755 -o root -g root'))->toBeTrue()
        // The download is not left in the panel's storage.
        ->and(glob(storage_path('app/addon-downloads/*')))->toBe([]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->getJson('/api/central/addons/runs/'.$response->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.status', 'succeeded');

    expect(ActivityLog::where('type', 'addon')->where('action', 'installed')->first()->properties)
        ->toMatchArray(['addon' => 'WP Toolkit', 'version' => '2.0.0']);
});

it('queues the install rather than running it in the request', function () {
    Queue::fake();

    installAddon('wp-toolkit', installBody($this->binary))
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(RunAddonLifecycle::class, fn ($job) => $job->queue === 'default');
});

it('refuses a URL that is not https on Central\'s own host, before anything is queued', function (string $url) {
    installAddon('wp-toolkit', installBody($this->binary, ['url' => $url]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(AddonRun::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'another host' => 'https://evil.test/wp-toolkit',
    'plain http' => 'http://central.test/wp-toolkit',
    'userinfo trick' => 'https://central.test@evil.test/wp-toolkit',
    'subdomain' => 'https://central.test.evil.test/wp-toolkit',
]);

it('refuses a malformed checksum or signature at the request', function () {
    installAddon('wp-toolkit', installBody($this->binary, ['sha256' => 'abc']))->assertJsonValidationErrors('sha256');
    installAddon('wp-toolkit', installBody($this->binary, ['signature' => base64_encode('short')]))->assertJsonValidationErrors('signature');
    installAddon('wp-toolkit', installBody($this->binary, ['retention_days' => 7]))->assertJsonValidationErrors('retention_days');
    installAddon('log-monitoring-suite', installBody($this->binary, ['retention_days' => 9]))->assertJsonValidationErrors('retention_days');

    expect(AddonRun::count())->toBe(0);
});

it('refuses to install anything when no signing key is configured', function () {
    config(['server.addons.signing_public_key' => null]);

    installAddon('wp-toolkit', installBody($this->binary))
        ->assertStatus(503)
        ->assertJsonPath('code', 'addon_signing_key_missing');

    expect(AddonRun::count())->toBe(0)
        ->and(file_exists("{$this->dir}/bin/wp-toolkit"))->toBeFalse();
    Http::assertNothingSent();
});

it('fails closed in the job too, if the key is gone by the time it runs', function () {
    Queue::fake();
    $run = installAddon('wp-toolkit', installBody($this->binary))->json('data.id');

    config(['server.addons.signing_public_key' => 'not-a-key']);
    app()->call([new RunAddonLifecycle($run), 'handle']);

    expect(AddonRun::find($run))->status->toBe('failed')
        ->and(AddonRun::find($run)->result['code'])->toBe('addon_signing_key_missing')
        ->and(file_exists("{$this->dir}/bin/wp-toolkit"))->toBeFalse();
});

describe('test mode (require_signature off)', function () {
    beforeEach(function () {
        config(['server.addons.require_signature' => false, 'server.addons.signing_public_key' => null]);
    });

    it('installs with no key and no signature', function () {
        $body = installBody($this->binary);
        unset($body['signature']);

        installAddon('wp-toolkit', $body)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'succeeded');

        expect(file_get_contents("{$this->dir}/bin/wp-toolkit"))->toBe($this->binary);
    });

    it('still refuses a download that does not match its checksum', function () {
        fakeAddonDownload("\x7fELF".random_bytes(256));

        installAddon('wp-toolkit', installBody($this->binary, ['signature' => null]))
            ->assertStatus(202)
            ->assertJsonPath('data.result.code', 'addon_checksum_mismatch');

        expect(file_exists("{$this->dir}/bin/wp-toolkit"))->toBeFalse();
    });

    it('still checks a signature that is sent when a key is set', function () {
        config(['server.addons.signing_public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);

        installAddon('wp-toolkit', installBody($this->binary))
            ->assertStatus(202)
            ->assertJsonPath('data.result.code', 'addon_signature_invalid');

        expect(file_exists("{$this->dir}/bin/wp-toolkit"))->toBeFalse();
    });
});

it('requires a signature in the request when signatures are required', function () {
    $body = installBody($this->binary);
    unset($body['signature']);

    installAddon('wp-toolkit', $body)->assertJsonValidationErrors('signature');
});

it('is in test mode by default until launch', function () {
    expect((require base_path('config/server.php'))['addons']['require_signature'])->toBeFalse();
})->skip(fn () => getenv('ADDON_REQUIRE_SIGNATURE') !== false, 'ADDON_REQUIRE_SIGNATURE is set in this environment');

it('refuses a download that does not match, is not signed by Central, or is not a program', function (Closure $tamper, string $code) {
    [$bytes, $body] = $tamper($this->binary, $this->secretKey);
    fakeAddonDownload($bytes);

    installAddon('wp-toolkit', $body)
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.result.code', $code);

    // Nothing written, nothing kept.
    expect(file_exists("{$this->dir}/bin/wp-toolkit"))->toBeFalse()
        ->and(ranLifecycle('install'))->toBeFalse()
        ->and(glob(storage_path('app/addon-downloads/*')))->toBe([])
        ->and(ActivityLog::where('type', 'addon')->where('action', 'install_failed')->exists())->toBeTrue();
})->with([
    'bad sha256' => [fn ($bytes, $key) => [$bytes.'x', installBody($bytes)], 'addon_checksum_mismatch'],
    'signed by someone else' => [function ($bytes) {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        return [$bytes, installBody($bytes, ['signature' => base64_encode(sodium_crypto_sign_detached($bytes, $other))])];
    }, 'addon_signature_invalid'],
    'signature of other bytes' => [fn ($bytes, $key) => [$bytes, installBody($bytes, ['signature' => base64_encode(sodium_crypto_sign_detached('other', $key))])], 'addon_signature_invalid'],
    'not ELF, though signed' => [function () {
        $html = '<html>error</html>';

        return [$html, installBody($html)];
    }, 'addon_not_executable'],
]);

it('refuses a download larger than the limit', function () {
    config(['server.addons.download_max_bytes' => 100]);

    installAddon('wp-toolkit', installBody($this->binary))
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.result.code', 'addon_download_failed');

    expect(file_exists("{$this->dir}/bin/wp-toolkit"))->toBeFalse();
});

it('allows one install or remove per addon at a time', function () {
    Queue::fake();

    installAddon('wp-toolkit', installBody($this->binary))->assertStatus(202);

    installAddon('wp-toolkit', installBody($this->binary))
        ->assertStatus(409)
        ->assertJsonPath('code', 'addon_busy')
        ->assertJsonPath('message', __('errors/addons.busy', ['addon' => 'WP Toolkit']));
    removeAddon('wp-toolkit')->assertStatus(409);

    // The other addon is not held up.
    removeAddon('log-monitoring-suite')->assertStatus(202);

    expect(AddonRun::count())->toBe(2);
});

it('no longer counts a run a dead worker left behind', function () {
    Queue::fake();
    $stale = AddonRun::create(['addon' => 'wp-toolkit', 'command' => 'install', 'status' => 'running', 'arguments' => []]);
    AddonRun::whereKey($stale->id)->update(['created_at' => now()->subHours(4)]);

    installAddon('wp-toolkit', installBody($this->binary))->assertStatus(202);

    expect($stale->fresh()->status)->toBe('failed');
});

it('closes the run when the worker kills the job, so the next one is not held off', function () {
    Queue::fake();
    $run = installAddon('log-monitoring-suite', installBody($this->binary))->json('data.id');

    (new RunAddonLifecycle($run))->failed(null);

    expect(AddonRun::find($run))
        ->status->toBe('failed')
        ->http_status->toBe(504)
        ->and(AddonRun::find($run)->result['message'])->toBe(__('errors/addons.timed_out', ['addon' => 'Log Monitoring Suite']));

    installAddon('log-monitoring-suite', installBody($this->binary))->assertStatus(202);
});

it('updates an installed binary and drops the backup once it worked', function () {
    placeBinary("{$this->dir}/bin/wp-toolkit", "\x7fELFold");

    installAddon('wp-toolkit', installBody($this->binary))
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result.action', 'updated');

    expect(file_get_contents("{$this->dir}/bin/wp-toolkit"))->toBe($this->binary)
        ->and(file_exists("{$this->dir}/bin/wp-toolkit.panel-previous"))->toBeFalse()
        ->and(ActivityLog::where('type', 'addon')->where('action', 'updated')->exists())->toBeTrue();
});

// ── installing Log Monitoring Suite ──────────────────────────────────────────

it('installs Log Monitoring Suite: binary, .env, schema, timers, sites', function () {
    installAddon('log-monitoring-suite', installBody($this->binary, ['retention_days' => 14]))
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result.action', 'installed')
        ->assertJsonPath('data.result.retention_days', 14)
        ->assertJsonPath('data.result.sites.registered', 1)
        ->assertJsonPath('data.result.v7_removed', false);

    $dir = $this->dir;

    expect(file_get_contents("{$dir}/bin/log-monitoring-suite"))->toBe($this->binary)
        ->and(file_get_contents("{$dir}/etc-lms/.env"))->toContain('DB_CONNECTION=sqlite')
        ->and(file_get_contents("{$dir}/etc-lms/.env"))->toContain("DB_DATABASE={$dir}/var-lms/log-monitoring-suite.sqlite")
        ->and(file_get_contents("{$dir}/etc-lms/.env"))->toMatch('/^APP_KEY=.{32}$/m')
        ->and(fileperms("{$dir}/etc-lms/.env") & 0777)->toBe(0600)
        ->and(is_dir("{$dir}/var-lms"))->toBeTrue()
        ->and(ranLifecycle("install -d -m 0700 -o root -g root {$dir}/var-lms"))->toBeTrue()
        ->and(ranLifecycle("install -d -m 0755 -o root -g root {$dir}/etc-lms"))->toBeTrue();

    $fetch = file_get_contents("{$dir}/units/log-monitoring-suite-fetch.service");
    $prune = file_get_contents("{$dir}/units/log-monitoring-suite-prune.service");
    $fetchTimer = file_get_contents("{$dir}/units/log-monitoring-suite-fetch.timer");

    expect($fetch)->toContain("WorkingDirectory={$dir}/etc-lms")
        ->and($fetch)->toContain("ExecStart={$dir}/bin/log-monitoring-suite logs:fetch")
        ->and($prune)->toContain("WorkingDirectory={$dir}/etc-lms")
        ->and($prune)->toContain("ExecStart={$dir}/bin/log-monitoring-suite logs:prune --days=14")
        ->and($fetchTimer)->toContain('OnCalendar=*:0/15')
        ->and($fetchTimer)->toContain('RandomizedDelaySec=60')
        ->and($fetchTimer)->toContain('Persistent=true')
        ->and(file_get_contents("{$dir}/units/log-monitoring-suite-prune.timer"))->toContain('OnCalendar=daily');

    // The schema is created from its working directory, where its .env is.
    expect($GLOBALS['lifecycleCwd']["{$dir}/bin/log-monitoring-suite schema:migrate"])->toBe("{$dir}/etc-lms")
        ->and(ranLifecycle('systemctl daemon-reload'))->toBeTrue()
        ->and(ranLifecycle('systemctl enable --now log-monitoring-suite-fetch.timer log-monitoring-suite-prune.timer'))->toBeTrue()
        ->and(ranLifecycle('applications:add --name=blog'))->toBeTrue()
        ->and($this->site->fresh()->log_monitoring_suite_id)->toBe(41);
});

it('keeps 7 days of access logs unless told otherwise, as v7 did', function () {
    installAddon('log-monitoring-suite', installBody($this->binary))->assertJsonPath('data.status', 'succeeded');

    expect(file_get_contents("{$this->dir}/units/log-monitoring-suite-prune.service"))->toContain('logs:prune --days=7');
});

it('refuses Log Monitoring Suite with 5 GiB or less free, touching nothing', function () {
    fakeLifecycle(['df ' => ["Avail\n".(5 * 1073741824)."\n"]]);

    installAddon('log-monitoring-suite', installBody($this->binary))
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.result.code', 'addon_disk_low');

    expectNoLmsOnDisk();
    expect(ranLifecycle('systemctl'))->toBeFalse();
});

it('removes v7\'s insighthub agent, data included, before installing', function () {
    $dir = $this->dir;
    File::ensureDirectoryExists("{$dir}/v7/lib");
    File::ensureDirectoryExists("{$dir}/v7/insighthub");
    File::ensureDirectoryExists("{$dir}/v7/log");
    file_put_contents("{$dir}/v7/lib/insighthub.service", "[Service]\n");
    file_put_contents("{$dir}/v7/insighthub/insighthub-db", 'old data');

    installAddon('log-monitoring-suite', installBody($this->binary))
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result.v7_removed', true);

    $commands = collect($GLOBALS['lifecycleRan'])->map(fn ($c) => implode(' ', $c))->values();
    $stopped = $commands->search('systemctl disable --now insighthub.service');
    $installed = $commands->search(fn ($c) => str_starts_with($c, 'install -m 0755'));

    expect($stopped)->not->toBeFalse()
        ->and($stopped)->toBeLessThan($installed)
        ->and(file_exists("{$dir}/v7/lib/insighthub.service"))->toBeFalse()
        ->and(file_exists("{$dir}/v7/insighthub"))->toBeFalse()
        ->and(file_exists("{$dir}/v7/log"))->toBeFalse();
});

it('leaves nothing behind when a fresh install fails part way', function () {
    fakeLifecycle(['enable --now' => ['', 'Failed to enable unit', 1]]);

    installAddon('log-monitoring-suite', installBody($this->binary))
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.result.code', 'addon_install_failed');

    expectNoLmsOnDisk();
    expect(ranLifecycle('systemctl disable --now log-monitoring-suite-fetch.timer log-monitoring-suite-prune.timer'))->toBeTrue()
        ->and($this->site->fresh()->log_monitoring_suite_id)->toBeNull()
        ->and(ActivityLog::where('type', 'addon')->where('action', 'install_failed')->exists())->toBeTrue();
});

it('passes the addon\'s own licence refusal through, and rolls back', function () {
    fakeLifecycle(['schema:migrate' => ['', '{"status":"error","code":"licence_required","message":"no purchase"}', 1]]);

    installAddon('log-monitoring-suite', installBody($this->binary))
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.http_status', 403)
        ->assertJsonPath('data.result.code', 'addon_licence_required');

    expectNoLmsOnDisk();
});

it('puts the previous binary and files back when an update fails', function () {
    $dir = $this->dir;
    installAddon('log-monitoring-suite', installBody($this->binary, ['retention_days' => 30]))->assertJsonPath('data.status', 'succeeded');
    $before = file_get_contents("{$dir}/units/log-monitoring-suite-prune.service");

    $new = "\x7fELF".random_bytes(300);
    fakeAddonDownload($new);
    fakeLifecycle(['daemon-reload' => fn () => ranLifecycle('tee') ? ['', 'boom', 1] : null]);

    installAddon('log-monitoring-suite', installBody($new, ['retention_days' => 7]))
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.result.code', 'addon_install_failed');

    expect(file_get_contents("{$dir}/bin/log-monitoring-suite"))->toBe($this->binary)
        ->and(file_get_contents("{$dir}/units/log-monitoring-suite-prune.service"))->toBe($before)
        ->and(glob("{$dir}/bin/*.panel-previous"))->toBe([])
        ->and(glob("{$dir}/units/*.panel-previous"))->toBe([])
        ->and(is_dir("{$dir}/var-lms"))->toBeTrue()
        // An update never disables the timers it found running.
        ->and(ranLifecycle('systemctl disable --now'))->toBeFalse();
});

it('puts the previous wp-toolkit back when its update fails', function () {
    placeBinary("{$this->dir}/bin/wp-toolkit", "\x7fELFold");
    fakeLifecycle(['install -m 0755' => ['', 'No space left on device', 1]]);

    installAddon('wp-toolkit', installBody($this->binary))->assertJsonPath('data.status', 'failed');

    expect(file_get_contents("{$this->dir}/bin/wp-toolkit"))->toBe("\x7fELFold")
        ->and(file_exists("{$this->dir}/bin/wp-toolkit.panel-previous"))->toBeFalse();
});

it('only registers sites the addon does not know yet on an update', function () {
    installAddon('log-monitoring-suite', installBody($this->binary))->assertJsonPath('data.status', 'succeeded');
    fakeLifecycle();

    installAddon('log-monitoring-suite', installBody($this->binary))
        ->assertJsonPath('data.result.action', 'updated')
        ->assertJsonPath('data.result.sites.registered', 0);

    expect(ranLifecycle('applications:add'))->toBeFalse();
});

it('reports a site the addon refused without undoing the install', function () {
    fakeLifecycle(['applications:add' => ['', '{"status":"error","message":"domain taken","code":"conflict","field":"domain"}', 1]]);

    installAddon('log-monitoring-suite', installBody($this->binary))
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result.sites.registered', 0)
        ->assertJsonPath('data.result.sites.failed.0.application_id', $this->site->id);

    expect(file_exists("{$this->dir}/bin/log-monitoring-suite"))->toBeTrue();
});

// ── removing ─────────────────────────────────────────────────────────────────

it('removes Log Monitoring Suite completely: timers, units, binary, config, data', function () {
    installAddon('log-monitoring-suite', installBody($this->binary))->assertJsonPath('data.status', 'succeeded');
    expect($this->site->fresh()->log_monitoring_suite_id)->toBe(41);
    fakeLifecycle();

    removeAddon('log-monitoring-suite')
        ->assertStatus(202)
        ->assertJsonPath('data.command', 'remove')
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result.action', 'removed');

    expectNoLmsOnDisk();

    $commands = collect($GLOBALS['lifecycleRan'])->map(fn ($c) => implode(' ', $c))->values();
    $disabled = $commands->search('systemctl disable --now log-monitoring-suite-fetch.timer log-monitoring-suite-prune.timer');
    $deleted = $commands->search(fn ($c) => str_starts_with($c, 'rm -rf') && str_contains($c, 'var-lms'));

    // Stopped before its database is deleted from under it.
    expect($disabled)->toBeInt()
        ->and($deleted)->toBeInt()
        ->and($disabled)->toBeLessThan($deleted)
        ->and(ranLifecycle('systemctl daemon-reload'))->toBeTrue()
        ->and($this->site->fresh()->log_monitoring_suite_id)->toBeNull()
        ->and(ActivityLog::where('type', 'addon')->where('action', 'removed')->first()->properties)->toMatchArray(['addon' => 'Log Monitoring Suite']);
});

it('removes wp-toolkit', function () {
    placeBinary("{$this->dir}/bin/wp-toolkit", "\x7fELFold");

    removeAddon('wp-toolkit')->assertJsonPath('data.status', 'succeeded');

    expect(file_exists("{$this->dir}/bin/wp-toolkit"))->toBeFalse();
});

it('succeeds removing an addon that is not installed', function () {
    removeAddon('log-monitoring-suite')->assertJsonPath('data.status', 'succeeded');
    removeAddon('wp-toolkit')->assertJsonPath('data.status', 'succeeded');

    // No units to stop: systemctl is not asked to disable what is not there.
    expect(ranLifecycle('systemctl disable'))->toBeFalse();
});

it('reports a removal step that failed', function () {
    placeBinary("{$this->dir}/bin/wp-toolkit", "\x7fELFold");
    fakeLifecycle(['rm -f' => ['', 'Operation not permitted', 1]]);

    removeAddon('wp-toolkit')
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.result.code', 'addon_remove_failed');

    expect(ActivityLog::where('type', 'addon')->where('action', 'remove_failed')->exists())->toBeTrue();
});

// ── health ───────────────────────────────────────────────────────────────────

it('reports whether ingestion is running, and whether an install is under way', function () {
    installAddon('log-monitoring-suite', installBody($this->binary))->assertJsonPath('data.status', 'succeeded');

    fakeLifecycle(['systemctl show' => [implode("\n", [
        'Id=log-monitoring-suite-fetch.timer', 'ActiveState=active', 'ExecMainExitTimestamp=', '',
        'Id=log-monitoring-suite-prune.timer', 'ActiveState=active', 'ExecMainExitTimestamp=', '',
        'Id=log-monitoring-suite-fetch.service', 'ActiveState=inactive', 'ExecMainExitTimestamp=Thu 2026-11-05 09:15:42 UTC', '',
    ])]]);

    Queue::fake();
    removeAddon('wp-toolkit')->assertStatus(202);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/central/addons')
        ->assertOk()
        ->assertJsonPath('addons.0.name', 'wp-toolkit')
        ->assertJsonPath('addons.0.installing', true)
        ->assertJsonMissingPath('addons.0.timers_active')
        ->assertJsonPath('addons.1.name', 'log-monitoring-suite')
        ->assertJsonPath('addons.1.installed', true)
        ->assertJsonPath('addons.1.installing', false)
        ->assertJsonPath('addons.1.timers_active', true)
        ->assertJsonPath('addons.1.last_fetch_at', '2026-11-05T09:15:42+00:00')
        ->assertJsonPath('addons.1.data_bytes', 4096)
        ->assertJsonPath('addons.1.retention_days', 7);
});

it('reports ingestion as stopped when either timer is not active', function (string $fetch, string $prune) {
    installAddon('log-monitoring-suite', installBody($this->binary))->assertJsonPath('data.status', 'succeeded');

    fakeLifecycle(['systemctl show' => [implode("\n", [
        'Id=log-monitoring-suite-fetch.timer', "ActiveState={$fetch}", 'ExecMainExitTimestamp=', '',
        'Id=log-monitoring-suite-prune.timer', "ActiveState={$prune}", 'ExecMainExitTimestamp=', '',
        'Id=log-monitoring-suite-fetch.service', 'ActiveState=inactive', 'ExecMainExitTimestamp=', '',
    ])]]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/central/addons')
        ->assertJsonPath('addons.1.timers_active', false)
        ->assertJsonPath('addons.1.last_fetch_at', null);
})->with([
    'fetch stopped' => ['inactive', 'active'],
    'prune stopped' => ['active', 'inactive'],
]);

it('reports no health for Log Monitoring Suite when it is not installed', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/central/addons')
        ->assertJsonPath('addons.1.installed', false)
        ->assertJsonPath('addons.1.timers_active', false)
        ->assertJsonPath('addons.1.data_bytes', null)
        ->assertJsonPath('addons.1.retention_days', null);

    expect(ranLifecycle('systemctl'))->toBeFalse();
});
