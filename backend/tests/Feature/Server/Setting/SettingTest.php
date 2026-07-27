<?php

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->dir = sys_get_temp_dir().'/sv-oss-set-'.uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->redisCli = $this->dir.'/redis-cli';
    File::put($this->redisCli, '');
    File::put($this->dir.'/meminfo', "SwapTotal:       2000000 kB\nSwapFree:        1500000 kB\n");
    $this->swapFile = $this->dir.'/swapfile';
    $this->fstab = $this->dir.'/fstab';

    config([
        'server.sshd_config_dir' => $this->dir,
        'server.unattended_upgrades_file' => $this->dir.'/99-panel-upgrades',
        'server.reboot_required_file' => $this->dir.'/reboot-required',
        'server.redis_cli' => $this->redisCli,
        'server.proc_dir' => $this->dir,
        'server.swap_file' => $this->swapFile,
        'server.fstab' => $this->fstab,
    ]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function fakeSettings(): void
{
    $redis = test()->redisCli;
    Process::fake(function ($process) use ($redis) {
        $cmd = $process->command;
        $bin = $cmd[0] ?? '';

        if ($bin === 'timedatectl' && ($cmd[1] ?? '') === 'show') {
            $value = str_contains(implode(' ', $cmd), 'NTP') ? 'yes' : 'Etc/UTC';

            return Process::result(output: $value);
        }
        if ($bin === 'hostnamectl' && ($cmd[1] ?? '') === '--static') {
            return Process::result(output: 'server.example');
        }
        if ($bin === 'sshd' && ($cmd[1] ?? '') === '-T') {
            return Process::result(output: "port 22\npermitrootlogin prohibit-password\npasswordauthentication yes\n");
        }
        if ($bin === 'ufw') {
            return Process::result(output: "Status: inactive\n");
        }
        if ($bin === $redis && ($cmd[1] ?? '') === 'config' && ($cmd[2] ?? '') === 'get') {
            $key = $cmd[3] ?? '';
            $value = match ($key) {
                'maxmemory' => '0',
                'maxmemory-policy' => 'noeviction',
                default => '',
            };

            return Process::result(output: "{$key}\n{$value}");
        }

        return Process::result(exitCode: 0);
    });
}

it('reads all available setting groups', function () {
    fakeSettings();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/settings')->assertOk();

    $response->assertJsonPath('settings.general.timezone', 'Etc/UTC')
        ->assertJsonPath('settings.general.ntp', true)
        ->assertJsonPath('settings.general.hostname', 'server.example')
        ->assertJsonPath('settings.security.port', 22)
        ->assertJsonPath('settings.security.password_authentication', true)
        ->assertJsonPath('settings.updates.reboot_required', false)
        ->assertJsonPath('settings.redis.has_password', false)
        ->assertJsonPath('settings.swap.enabled', true)   // 2000000 kB in the meminfo fixture
        ->assertJsonPath('settings.swap.size', 2048000000)
        ->assertJsonPath('settings.swap.path', $this->swapFile);
});

it('creates a swap file, activates it and adds an fstab entry', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/swap', ['size_mb' => 2048])->assertOk()
        ->assertJsonPath('swap.path', $this->swapFile);

    Process::assertRan(fn ($p) => $p->command === ['fallocate', '-l', '2048M', $this->swapFile]);
    Process::assertRan(fn ($p) => $p->command === ['mkswap', $this->swapFile]);
    Process::assertRan(fn ($p) => $p->command === ['swapon', $this->swapFile]);
    expect(File::get($this->fstab))->toContain($this->swapFile.' none swap sw 0 0');
    $this->assertDatabaseHas('activity_logs', ['type' => 'setting', 'action' => 'updated']);
});

it('disables swap and strips only its fstab line', function () {
    fakeSettings();
    File::put($this->fstab, "UUID=abc / ext4 defaults 0 1\n{$this->swapFile} none swap sw 0 0\n");
    File::put($this->swapFile, 'x');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/swap', ['size_mb' => 0])->assertOk();

    expect(File::exists($this->swapFile))->toBeFalse();
    expect(File::get($this->fstab))
        ->toContain('UUID=abc / ext4 defaults 0 1')
        ->not->toContain($this->swapFile);
});

it('rejects a swap size over the configured ceiling', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/swap', ['size_mb' => 999999])
        ->assertStatus(422)->assertJsonValidationErrorFor('size_mb');
});

it('schedules a server reboot and logs it', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/settings/reboot')->assertStatus(202)
        ->assertJsonPath('reboot.scheduled', true)
        ->assertJsonPath('reboot.when', 'now');

    Process::assertRan(fn ($p) => $p->command === ['shutdown', '-r', 'now']);
    $this->assertDatabaseHas('activity_logs', ['type' => 'setting', 'action' => 'reboot_requested']);
});

it('reboots with a grace delay when requested', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/settings/reboot', ['delay_minutes' => 5])->assertStatus(202)
        ->assertJsonPath('reboot.when', '+5');

    Process::assertRan(fn ($p) => $p->command === ['shutdown', '-r', '+5']);
});

it('denies reboot for a viewer without manage', function () {
    fakeSettings();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'setting', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/settings/reboot')->assertForbidden();
});

it('updates general settings and logs it', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/general', ['timezone' => 'Asia/Kolkata', 'hostname' => 'web-01', 'ntp' => true])
        ->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['timedatectl', 'set-timezone', 'Asia/Kolkata']);
    Process::assertRan(fn ($p) => $p->command === ['hostnamectl', 'set-hostname', 'web-01']);
    expect(ActivityLog::where('type', 'setting')->where('action', 'updated')->exists())->toBeTrue();
});

it('rejects an invalid timezone', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/general', ['timezone' => 'Not/AZone', 'hostname' => 'web-01', 'ntp' => true])
        ->assertJsonValidationErrors('timezone');
});

it('writes the ssh drop-in, tests then reloads', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/security', ['port' => 2222, 'permit_root_login' => 'no', 'password_authentication' => true])
        ->assertOk()
        ->assertJsonPath('security.port', 22); // read is re-run via fake (still 22)

    $dropIn = File::get($this->dir.'/99-panel.conf');
    expect($dropIn)->toContain('Port 2222')->toContain('PermitRootLogin no')->toContain('PasswordAuthentication yes');
    Process::assertRan(fn ($p) => $p->command === ['sshd', '-t']);
    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'ssh']);
});

it('blocks disabling password auth with no ssh key (lockout guard)', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/security', ['port' => 22, 'permit_root_login' => 'prohibit-password', 'password_authentication' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password_authentication');
});

it('returns a translated error with reference when sshd -t fails', function () {
    Process::fake(function ($process) {
        if (($process->command[0] ?? '') === 'sshd' && ($process->command[1] ?? '') === '-t') {
            return Process::result(output: '', errorOutput: 'bad config', exitCode: 1);
        }
        if (($process->command[0] ?? '') === 'sshd') {
            return Process::result(output: "port 22\npermitrootlogin yes\npasswordauthentication yes\n");
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/security', ['port' => 2222, 'permit_root_login' => 'yes', 'password_authentication' => true])
        ->assertStatus(500)
        ->assertJsonStructure(['message', 'reference']);
});

it('writes the unattended-upgrades drop-in', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/updates', ['security_updates_enabled' => true, 'auto_reboot' => true, 'reboot_time' => '03:30'])
        ->assertOk();

    $conf = File::get($this->dir.'/99-panel-upgrades');
    expect($conf)->toContain('APT::Periodic::Unattended-Upgrade "1"')
        ->toContain('Unattended-Upgrade::Automatic-Reboot "true"')
        ->toContain('Automatic-Reboot-Time "03:30"');
});

it('applies redis settings via redis-cli', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/redis', ['maxmemory' => '256mb', 'maxmemory_policy' => 'allkeys-lru', 'password' => null])
        ->assertOk();

    Process::assertRan(fn ($p) => $p->command === [$this->redisCli, 'config', 'set', 'maxmemory', '256mb']);
    Process::assertRan(fn ($p) => $p->command === [$this->redisCli, 'config', 'rewrite']);
});

it('omits redis and 404s its update when redis-cli is absent', function () {
    config(['server.redis_cli' => $this->dir.'/nope']);
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/settings')->assertOk()
        ->assertJsonMissingPath('settings.redis');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/redis', ['maxmemory' => '0', 'maxmemory_policy' => 'noeviction'])
        ->assertNotFound();
});

it('denies a viewer without manage from changing settings', function () {
    fakeSettings();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'setting', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/settings')->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/settings/general', ['timezone' => 'Etc/UTC', 'hostname' => 'x', 'ntp' => true])
        ->assertForbidden();
});
