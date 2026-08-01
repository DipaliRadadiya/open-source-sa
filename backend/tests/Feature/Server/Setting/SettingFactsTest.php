<?php

use App\Models\ActivityLog;
use App\Models\SshKey;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/**
 * The read-only facts the settings UI needs in order to say something true
 * about each section: how many updates are waiting, whether the last
 * unattended run worked, whether a key exists, whether the clock is actually
 * synchronised, whether Redis is up.
 *
 * The recurring assertion in here is `null` rather than a value. Each of these
 * reads can fail for ordinary reasons — a package that is not installed, a log
 * the panel cannot open — and reporting a confident `0` or `false` in those
 * cases would be worse than reporting nothing, because it is the same shape as
 * a real answer.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->dir = sys_get_temp_dir().'/sv-oss-facts-'.uniqid();
    File::ensureDirectoryExists($this->dir);

    $this->aptCheck = $this->dir.'/apt-check';
    $this->stamp = $this->dir.'/update-success-stamp';
    $this->redisCli = $this->dir.'/redis-cli';
    File::put($this->redisCli, '');

    config([
        'server.sshd_config_dir' => $this->dir,
        'server.unattended_upgrades_file' => $this->dir.'/99-panel-upgrades',
        'server.reboot_required_file' => $this->dir.'/reboot-required',
        'server.apt_check' => $this->aptCheck,
        'server.apt_update_stamp' => $this->stamp,
        'server.unattended_upgrades_log' => $this->dir.'/unattended.log',
        'server.redis_cli' => $this->redisCli,
        'server.proc_dir' => $this->dir,
        'server.swap_file' => $this->dir.'/swapfile',
        'server.fstab' => $this->dir.'/fstab',
    ]);

    File::put($this->dir.'/meminfo', "SwapTotal:             0 kB\nSwapFree:              0 kB\n");
});

afterEach(fn () => File::deleteDirectory($this->dir));

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeFacts(array $overrides = []): void
{
    $aptCheck = test()->aptCheck;
    $redis = test()->redisCli;

    Process::fake(function ($process) use ($aptCheck, $redis, $overrides) {
        $cmd = $process->command;
        $bin = $cmd[0] ?? '';

        if ($bin === 'tee') {
            File::put($cmd[1], (string) $process->input);

            return Process::result(exitCode: 0);
        }

        // apt-check answers on stderr and says nothing on stdout.
        if ($bin === $aptCheck) {
            return array_key_exists('apt_check', $overrides)
                ? $overrides['apt_check']
                : Process::result(errorOutput: '43;1');
        }

        if ($bin === 'tail') {
            return $overrides['unattended'] ?? Process::result(exitCode: 1);
        }

        if ($bin === 'timedatectl' && ($cmd[1] ?? '') === 'show') {
            $property = $cmd[2] ?? '';

            if (str_contains($property, 'NTPSynchronized')) {
                return Process::result(output: $overrides['synchronized'] ?? 'yes');
            }
            if (str_contains($property, 'NTP')) {
                return Process::result(output: 'yes');
            }

            return Process::result(output: 'Etc/UTC');
        }

        if ($bin === 'hostnamectl') {
            return Process::result(output: 'server.example');
        }
        if ($bin === 'sshd' && ($cmd[1] ?? '') === '-T') {
            return Process::result(output: "port 22\npermitrootlogin prohibit-password\npasswordauthentication yes\n");
        }
        if ($bin === 'ufw') {
            return Process::result(output: "Status: inactive\n");
        }

        if ($bin === $redis) {
            if (($cmd[1] ?? '') === 'ping') {
                return $overrides['ping'] ?? Process::result(output: 'PONG');
            }
            if (($cmd[1] ?? '') === 'info') {
                return $overrides['info'] ?? Process::result(
                    output: "# Memory\nused_memory:1048576\nused_memory_human:1.00M\n",
                );
            }
            if (($cmd[1] ?? '') === 'config') {
                $key = $cmd[3] ?? '';

                return Process::result(output: $key."\n".match ($key) {
                    'maxmemory' => '0',
                    'maxmemory-policy' => 'noeviction',
                    default => '',
                });
            }
        }

        return Process::result(exitCode: 0);
    });
}

function readSettings(): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/settings')->assertOk();
}

it('counts pending and security updates from apt-check stderr', function () {
    File::put($this->aptCheck, '');
    fakeFacts();

    readSettings()
        ->assertJsonPath('settings.updates.updates_available', 43)
        ->assertJsonPath('settings.updates.security_updates_available', 1);
});

it('reports null update counts when apt-check is not installed', function () {
    fakeFacts();
    // No file at the configured path — update-notifier-common is absent.

    readSettings()
        ->assertJsonPath('settings.updates.updates_available', null)
        ->assertJsonPath('settings.updates.security_updates_available', null);
});

it('reports null rather than zero when apt-check fails', function () {
    File::put($this->aptCheck, '');
    fakeFacts(['apt_check' => Process::result(exitCode: 1, errorOutput: 'boom')]);

    // Zero would claim the server is up to date on the strength of a failure.
    readSettings()
        ->assertJsonPath('settings.updates.updates_available', null)
        ->assertJsonPath('settings.updates.security_updates_available', null);
});

it('reads the last successful apt refresh from the stamp file', function () {
    File::put($this->aptCheck, '');
    File::put($this->stamp, '');
    touch($this->stamp, mktime(9, 30, 0, 7, 14, 2026));
    fakeFacts();

    readSettings()->assertJsonPath('settings.updates.lists_refreshed_at', '14-07-2026 09:30:00');
});

it('reports a successful unattended-upgrades run', function () {
    File::put($this->aptCheck, '');
    fakeFacts(['unattended' => Process::result(output: implode("\n", [
        '2026-07-30 06:00:01,001 INFO Starting unattended upgrades script',
        '2026-07-30 06:00:02,002 ERROR something went wrong in a previous run',
        '2026-08-01 06:00:01,001 INFO Starting unattended upgrades script',
        '2026-08-01 06:00:09,123 INFO All upgrades installed',
    ]))]);

    // The older run's ERROR sits before the last start marker and must not
    // colour today's result.
    readSettings()
        ->assertJsonPath('settings.updates.unattended_last_result', 'success')
        ->assertJsonPath('settings.updates.unattended_last_run_at', '01-08-2026 06:00:09');
});

it('reports a failed unattended-upgrades run', function () {
    File::put($this->aptCheck, '');
    fakeFacts(['unattended' => Process::result(output: implode("\n", [
        '2026-08-01 06:00:01,001 INFO Starting unattended upgrades script',
        '2026-08-01 06:00:04,004 ERROR Could not fetch archives',
    ]))]);

    readSettings()
        ->assertJsonPath('settings.updates.unattended_last_result', 'failed')
        ->assertJsonPath('settings.updates.unattended_last_run_at', '01-08-2026 06:00:04');
});

it('reports nulls when the unattended log cannot be read', function () {
    File::put($this->aptCheck, '');
    fakeFacts(['unattended' => Process::result(exitCode: 1, errorOutput: 'Permission denied')]);

    readSettings()
        ->assertJsonPath('settings.updates.unattended_last_run_at', null)
        ->assertJsonPath('settings.updates.unattended_last_result', null);
});

it('reports whether the server has any ssh key', function () {
    fakeFacts();

    readSettings()->assertJsonPath('settings.security.has_ssh_key', false);

    $systemUser = SystemUser::query()->create([
        'username' => 'deploy',
        'shell' => '/bin/bash',
        'home_path' => '/home/deploy',
    ]);
    SshKey::query()->create([
        'system_user_id' => $systemUser->id,
        'name' => 'laptop',
        'public_key' => 'ssh-ed25519 AAAAC3Nz key',
        'fingerprint' => 'SHA256:abc',
    ]);

    readSettings()->assertJsonPath('settings.security.has_ssh_key', true);
});

it('distinguishes a synchronised clock from an enabled ntp daemon', function () {
    fakeFacts(['synchronized' => 'no']);

    readSettings()
        ->assertJsonPath('settings.general.ntp', true)
        ->assertJsonPath('settings.general.clock_synchronized', false);
});

it('reports redis memory usage when redis is running', function () {
    fakeFacts();

    readSettings()
        ->assertJsonPath('settings.redis.running', true)
        ->assertJsonPath('settings.redis.memory_used', 1048576)
        ->assertJsonPath('settings.redis.memory_used_human', '1.00M');
});

it('reports redis as running when it answers NOAUTH', function () {
    fakeFacts(['ping' => Process::result(exitCode: 1, errorOutput: 'NOAUTH Authentication required.')]);

    // A refusal to answer without a password is still proof of a live server.
    readSettings()->assertJsonPath('settings.redis.running', true);
});

it('reports redis as not running when it does not answer', function () {
    fakeFacts([
        'ping' => Process::result(exitCode: 1, errorOutput: 'Could not connect to Redis'),
    ]);

    readSettings()
        ->assertJsonPath('settings.redis.running', false)
        ->assertJsonPath('settings.redis.memory_used', null);
});

it('reports who last changed each settings group', function () {
    fakeFacts();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/general', [
            'timezone' => 'Etc/UTC',
            'hostname' => 'server.example',
            'ntp' => true,
        ])->assertOk();

    readSettings()
        ->assertJsonPath('last_changed.general.user.username', $this->admin->username)
        ->assertJsonPath('last_changed.general.user.id', $this->admin->id);
});

it('omits groups that have never been changed', function () {
    fakeFacts();

    $response = readSettings();

    expect($response->json('last_changed'))->toBe([]);
});

it('finds the reboot schedule change under its own verb', function () {
    fakeFacts();

    // It logs `setting.reboot_schedule_updated` with no `group` property, so a
    // lookup keyed on the group alone would report it as never changed.
    ActivityLog::query()->create([
        'user_id' => $this->admin->id,
        'type' => 'setting',
        'action' => 'reboot_schedule_updated',
        'properties' => ['enabled' => 'yes'],
    ]);

    readSettings()->assertJsonPath('last_changed.reboot_schedule.user.username', $this->admin->username);
});
