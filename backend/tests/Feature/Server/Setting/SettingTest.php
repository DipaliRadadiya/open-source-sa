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
        'server.hosts_file' => $this->dir.'/hosts',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function fakeSettings(bool $swapActive = false, bool $swapoffOk = true): void
{
    $redis = test()->redisCli;
    $swapFile = test()->swapFile;
    Process::fake(function ($process) use ($redis, $swapActive, $swapoffOk, $swapFile) {
        $cmd = $process->command;
        // ServerOps prefixes privileged operations with sudo in this test
        // environment; fakes assert the underlying command semantics.
        if (($cmd[0] ?? null) === 'sudo' && ($cmd[1] ?? null) === '-n') {
            $cmd = array_slice($cmd, 2);
        }
        $bin = $cmd[0] ?? '';

        // Checked before the `swapon` case below, which is the activation
        // call rather than the query and shares its binary.
        if ($bin === 'swapon' && ($cmd[1] ?? '') === '--show=NAME') {
            return Process::result(output: $swapActive ? $swapFile."\n" : '');
        }

        if ($bin === 'swapoff') {
            // What the kernel says when there is not enough free RAM to take
            // back the pages that are currently swapped out.
            return $swapoffOk
                ? Process::result(exitCode: 0)
                : Process::result(exitCode: 255, errorOutput: 'swapoff: '.$swapFile.': Cannot allocate memory');
        }

        // Config writes go through ServerOps now rather than File::put, so
        // the fake has to perform them — otherwise the file never appears
        // and the assertions below would be checking nothing at all.
        if ($bin === 'tee') {
            File::put($cmd[1], (string) $process->input);

            return Process::result(exitCode: 0);
        }
        // `cat` reads for real too — a fake that answers empty would have the
        // code believe a system file is blank.
        if ($bin === 'cat' && is_file($cmd[1] ?? '')) {
            return Process::result(output: File::get($cmd[1]));
        }
        if ($bin === 'rm') {
            File::delete(end($cmd));

            return Process::result(exitCode: 0);
        }

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
        // `enabled` is now whether the *panel's* swap file is on, not whether
        // the machine has swap at all. The fixture's 2 GB belongs to the
        // system, so it is reported separately and flagged unmanaged rather
        // than shown as something this screen created.
        ->assertJsonPath('settings.swap.enabled', false)
        ->assertJsonPath('settings.swap.size', 0)
        ->assertJsonPath('settings.swap.system_total', 2048000000)
        ->assertJsonPath('settings.swap.unmanaged', true)
        ->assertJsonPath('settings.swap.path', $this->swapFile);
});

it('creates a swap file, activates it and adds an fstab entry', function () {
    fakeSettings();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/swap', ['size_mb' => 2048])->assertOk()
        ->assertJsonPath('swap.path', $this->swapFile);

    // Allocated beside the live file and moved in, so a failed allocation
    // cannot leave the server with no swap at all.
    Process::assertRan(fn ($p) => $p->command === ['fallocate', '-l', '2048M', $this->swapFile.'.new']);
    Process::assertRan(fn ($p) => $p->command === ['mkswap', $this->swapFile.'.new']);
    Process::assertRan(fn ($p) => $p->command === ['mv', $this->swapFile.'.new', $this->swapFile]);
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

it('allocates a fresh file so a smaller size really shrinks', function () {
    // fallocate only ever allocates: given a length smaller than the file
    // already has it succeeds and changes nothing. Resizing 2 GB down to
    // 1.5 GB left a 2 GB file, mkswap made 2 GB of swap, and the screen came
    // back showing the old number — which is what "downgrading doesn't work"
    // was. Growing worked, so it only ever looked broken one way.
    fakeSettings(swapActive: true);
    File::put(test()->swapFile, 'x');

    test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson('/api/settings/swap', ['size_mb' => 1536])->assertOk();

    // A fresh file at the requested size, then moved over the old one — the
    // old file is never removed before its replacement exists, because the
    // usual reason an allocation fails is the full disk that prompted the
    // resize, and that used to leave the server with no swap at all.
    Process::assertRan(fn ($p) => $p->command === ['fallocate', '-l', '1536M', test()->swapFile.'.new']);
    Process::assertRan(fn ($p) => $p->command === ['mv', test()->swapFile.'.new', test()->swapFile]);
});

it('refuses to resize swap the server cannot give up, and says why', function () {
    // swapoff reads every swapped-out page back into RAM and refuses when
    // there is not enough free memory — the state a server changing its swap
    // is most likely to be in. This used to be ignored.
    fakeSettings(swapActive: true, swapoffOk: false);

    test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson('/api/settings/swap', ['size_mb' => 1536])
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/setting.swap_in_use'));

    // The part that matters: mkswap must not have rewritten the header of a
    // swap file the kernel is still using.
    Process::assertNotRan(fn ($p) => $p->command === ['mkswap', test()->swapFile]);
    Process::assertNotRan(fn ($p) => $p->command === ['rm', '-f', test()->swapFile]);
});

it('refuses to disable swap the server cannot give up', function () {
    fakeSettings(swapActive: true, swapoffOk: false);
    File::put(test()->swapFile, 'x');

    test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson('/api/settings/swap', ['size_mb' => 0])
        ->assertStatus(422);

    // Deleting the file out from under an active swap is worse than refusing.
    expect(File::exists(test()->swapFile))->toBeTrue();
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

    $dropIn = File::get($this->dir.'/00-panel.conf');
    expect($dropIn)->toContain('Port 2222')->toContain('PermitRootLogin no')->toContain('PasswordAuthentication yes')
        // `root` explicitly: AllowGroups is a whitelist over every account and
        // is consulted before PermitRootLogin, so a list without it locks root
        // out of a box most providers hand you as root.
        ->toContain('AllowGroups ssh-users sudo root');
    Process::assertRan(fn ($p) => in_array('sshd', $p->command, true) && in_array('-t', $p->command, true));
    Process::assertRan(fn ($p) => in_array('rm', $p->command, true) && in_array($this->dir.'/99-panel.conf', $p->command, true));
    Process::assertRan(fn ($p) => in_array('systemctl', $p->command, true) && in_array('reload', $p->command, true) && in_array('ssh', $p->command, true));
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

// The write above only ever proved the file was correct on disk. Nothing read
// it back, and the parser could not: every apt key it writes contains a hyphen
// and the key pattern excluded one, so the whole group read as its defaults and
// the toggle forgot itself on every reload.
it('reads the updates group back exactly as it was saved', function () {
    fakeSettings();

    $saved = [
        'security_updates_enabled' => true,
        'auto_reboot' => true,
        'reboot_time' => '03:30',
        'reboot_with_users' => true,
    ];

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/updates', $saved)->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/settings')->assertOk();

    foreach ($saved as $field => $value) {
        $response->assertJsonPath("settings.updates.{$field}", $value);
    }
});

// The all-true pass alone would also pass against a parser that lost the file
// and happened to default the other way, so the off state is asserted too.
it('reads the updates group back when everything is switched off', function () {
    fakeSettings();

    $saved = [
        'security_updates_enabled' => false,
        'auto_reboot' => false,
        'reboot_time' => '02:00',
        'reboot_with_users' => false,
    ];

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/updates', $saved)->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/settings')->assertOk();

    foreach ($saved as $field => $value) {
        $response->assertJsonPath("settings.updates.{$field}", $value);
    }
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

describe('the ssh whitelist cannot lock the administrator out', function () {
    /** `sshd -T` reporting whatever policy the box already has. */
    function fakeSshdPolicy(string $extra): void
    {
        Process::fake(function ($process) use ($extra) {
            $cmd = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($cmd[0] ?? '') === 'sshd' && ($cmd[1] ?? '') === '-T') {
                return Process::result(output: "port 22\npermitrootlogin prohibit-password\npasswordauthentication yes\n".$extra);
            }

            if (($cmd[0] ?? '') === 'ufw') {
                return Process::result(output: "Status: inactive\n");
            }

            // `tee` and `rm` are how ManagedFile works, so the fake has to do
            // them or the assertions below inspect a file that never appeared.
            if (($cmd[0] ?? '') === 'tee') {
                File::put($cmd[1], (string) $process->input);
            }

            if (($cmd[0] ?? '') === 'rm') {
                File::delete(end($cmd));
            }

            return Process::result(exitCode: 0);
        });
    }

    it('always leaves root a way in', function () {
        fakeSshdPolicy('');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/settings/security', ['port' => 22, 'permit_root_login' => 'prohibit-password', 'password_authentication' => true])
            ->assertOk();

        // PermitRootLogin still governs root; this only stops AllowGroups
        // refusing it before that setting is ever read.
        expect(File::get($this->dir.'/00-panel.conf'))->toContain('root');
    });

    it('widens a policy the server already had rather than narrowing it', function () {
        fakeSshdPolicy("allowgroups devops\n");

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/settings/security', ['port' => 22, 'permit_root_login' => 'no', 'password_authentication' => true])
            ->assertOk();

        // Someone chose `devops` deliberately. Dropping it would cut off
        // whoever that group exists for.
        expect(File::get($this->dir.'/00-panel.conf'))->toContain('devops');
    });

    it('writes no group whitelist at all when the server restricts by user', function () {
        fakeSshdPolicy("allowusers deployer\n");

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/settings/security', ['port' => 22, 'permit_root_login' => 'no', 'password_authentication' => true])
            ->assertOk();

        // AllowUsers and AllowGroups are ANDed: a user must match both. Adding
        // ours could only take access away from a list somebody else chose.
        expect(File::get($this->dir.'/00-panel.conf'))->not->toContain('AllowGroups');
    });

    it('creates the group before naming it in the config', function () {
        fakeSshdPolicy('');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/settings/security', ['port' => 22, 'permit_root_login' => 'no', 'password_authentication' => true])
            ->assertOk();

        // A whitelist entry matching no group matches no user — it does not
        // fail, it just quietly stops being one of the ways in.
        Process::assertRan(fn ($p) => in_array('groupadd', $p->command, true)
            && in_array('ssh-users', $p->command, true));
    });
});

it('keeps the existing swap when the new one cannot be allocated', function () {
    // A full disk is the usual reason `fallocate` fails, and a full disk is
    // what has somebody resizing swap in the first place. Removing the old
    // file first meant that failure left the server with no swap at all and an
    // fstab line pointing at nothing.
    fakeSettings(swapActive: true);
    File::put(test()->swapFile, 'the swap in use right now');

    Process::fake(function ($process) {
        $cmd = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($cmd[0] ?? '') === 'swapon' && ($cmd[1] ?? '') === '--show=NAME') {
            return Process::result(output: test()->swapFile."\n");
        }

        if (($cmd[0] ?? '') === 'fallocate') {
            return Process::result(exitCode: 1, errorOutput: 'fallocate: no space left on device');
        }

        return Process::result(exitCode: 0);
    });

    test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson('/api/settings/swap', ['size_mb' => 4096])
        ->assertStatus(500);

    // Still there, still the swap the server is running on.
    expect(File::exists(test()->swapFile))->toBeTrue()
        ->and(File::get(test()->swapFile))->toBe('the swap in use right now');
    Process::assertNotRan(fn ($p) => $p->command === ['rm', '-f', test()->swapFile]);
});

it('makes the new hostname resolve, so sudo does not hang on it', function () {
    fakeSettings();
    File::put($this->dir.'/hosts', "127.0.0.1\tlocalhost\n127.0.1.1\tserver.example\n10.0.0.5\tinternal\n");

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/general', [
            'timezone' => 'Etc/UTC', 'ntp' => true, 'hostname' => 'renamed.example',
        ])->assertOk();

    $hosts = File::get($this->dir.'/hosts');

    // `hostnamectl` changes the name and nothing else; a name that does not
    // resolve makes every `sudo` wait out a DNS timeout, which reads as a
    // network fault rather than a settings change.
    expect($hosts)->toContain("127.0.1.1\trenamed.example")
        // One 127.0.1.1 line, not one per rename — otherwise the first one
        // wins and after two renames that is nobody's current hostname.
        ->and(substr_count($hosts, '127.0.1.1'))->toBe(1)
        // Somebody else's entries are not ours to tidy away.
        ->and($hosts)->toContain('10.0.0.5')
        ->and($hosts)->toContain('localhost');
});

it('leaves the hosts file alone when the hostname did not change', function () {
    fakeSettings();
    File::put($this->dir.'/hosts', "127.0.1.1\tserver.example\n");

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/general', [
            'timezone' => 'Europe/Berlin', 'ntp' => true, 'hostname' => 'server.example',
        ])->assertOk();

    Process::assertNotRan(fn ($p) => in_array('hostnamectl', $p->command, true)
        && in_array('set-hostname', $p->command, true));
});

it('never rewrites the hosts file from a read that came back empty', function () {
    fakeSettings();
    File::put($this->dir.'/hosts', "127.0.0.1\tlocalhost\n127.0.1.1\told.example\n");

    // Read succeeds but returns nothing. An empty hosts file does not exist in
    // reality — every machine has a localhost line — so believing this one
    // would replace localhost with our single entry.
    Process::fake(function ($process) {
        $cmd = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($cmd[0] ?? '') === 'hostnamectl' && ($cmd[1] ?? '') === '--static') {
            return Process::result(output: 'server.example');
        }

        if (($cmd[0] ?? '') === 'cat') {
            return Process::result(output: '');
        }

        if (($cmd[0] ?? '') === 'tee') {
            File::put($cmd[1], (string) $process->input);
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/settings/general', [
            'timezone' => 'Etc/UTC', 'ntp' => true, 'hostname' => 'renamed.example',
        ])->assertOk();

    expect(File::get($this->dir.'/hosts'))->toContain('localhost');
});

describe('a scheduled restart', function () {
    it('answers when it will happen, from the server\'s own clock', function () {
        // `when` is "+60". A client turning that into a time with its own clock
        // is wrong by however far the two have drifted — on the one number
        // where being wrong means somebody expects a restart at the wrong hour.
        // `shutdown` obeys the server's clock, so the server answers.
        fakeSettings();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/settings/reboot', ['delay_minutes' => 30])
            ->assertStatus(202);

        expect($response->json('reboot.delay_minutes'))->toBe(30)
            ->and($response->json('reboot.at'))
            ->toBe(now()->addMinutes(30)->format('d-m-Y H:i:s'));
    });

    it('reports one that is already pending, read from systemd', function () {
        // Read rather than remembered: a reboot can be scheduled from a shell
        // without the panel, and a panel that only knew about its own would
        // report "none" while the machine counts down.
        $at = now()->addHour()->startOfSecond();
        $file = $this->dir.'/shutdown-scheduled';
        File::put($file, 'USEC='.($at->getTimestamp() * 1_000_000)."\nWARN_WALL=1\nMODE=reboot\n");
        config(['server.reboot.scheduled_file' => $file]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/settings/reboot')
            ->assertOk()
            ->assertJsonPath('reboot.scheduled', true)
            ->assertJsonPath('reboot.at', $at->format('d-m-Y H:i:s'));
    });

    it('reports none when systemd has no pending shutdown', function () {
        config(['server.reboot.scheduled_file' => $this->dir.'/no-such-file']);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/settings/reboot')
            ->assertOk()
            ->assertJsonPath('reboot.scheduled', false)
            ->assertJsonPath('reboot.at', null);
    });

    it('needs no elevated privilege to answer', function () {
        // The first version of this asked sudo, which it never needed:
        // /run/systemd/shutdown is 0755 root. On a server whose grant was out
        // of date that returned 500 -- from an endpoint the settings screen
        // calls on load, so the whole page broke over a file anyone can read.
        $file = $this->dir.'/shutdown-scheduled';
        File::put($file, 'USEC='.(now()->addHour()->getTimestamp() * 1_000_000)."\n");
        config(['server.reboot.scheduled_file' => $file]);

        // Every command refused. The answer must not depend on one.
        Process::fake(fn () => Process::result(
            errorOutput: 'sudo: a password is required',
            exitCode: 1,
        ));

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/settings/reboot')
            ->assertOk()
            ->assertJsonPath('reboot.scheduled', true);
    });

    it('can be cancelled', function () {
        // `shutdown -c`, which had no route at all: a restart scheduled an
        // hour out could be watched and not stopped.
        fakeSettings();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson('/api/settings/reboot')
            ->assertOk()
            ->assertJsonPath('reboot.scheduled', false);

        Process::assertRan(fn ($p) => $p->command === ['shutdown', '-c']);
        $this->assertDatabaseHas('activity_logs', ['type' => 'setting', 'action' => 'reboot_cancelled']);
    });

    it('refuses to cancel for a viewer without manage', function () {
        fakeSettings();
        $viewer = User::factory()->create();
        grantPermission($viewer, 'setting', view: true, manage: false);

        $this->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
            ->deleteJson('/api/settings/reboot')
            ->assertForbidden();
    });
});

describe('the Redis password in the settings response', function () {
    /** A Redis that answers `CONFIG GET requirepass` with a real password. */
    function fakeRedisWithPassword(string $password = 's3cr3t-redis'): void
    {
        $redis = test()->redisCli;

        Process::fake(function ($process) use ($redis, $password) {
            $cmd = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($cmd[0] ?? '') === $redis && ($cmd[1] ?? '') === 'config' && ($cmd[2] ?? '') === 'get') {
                $key = $cmd[3] ?? '';
                $value = match ($key) {
                    'requirepass' => $password,
                    'maxmemory' => '0',
                    'maxmemory-policy' => 'noeviction',
                    default => '',
                };

                return Process::result(output: "{$key}\n{$value}");
            }

            return Process::result(exitCode: 0);
        });
    }

    it('sends the value to someone who could change it anyway', function () {
        // Operator decision, 2026-08-31: the panel shows and copies this the
        // way it already shows a system user's password.
        fakeRedisWithPassword();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/settings')->assertOk()
            ->assertJsonPath('settings.redis.has_password', true)
            ->assertJsonPath('settings.redis.password', 's3cr3t-redis');
    });

    it('withholds it from a read-only role, which still learns one is set', function () {
        // This is not a system user's password: Redis backs the panel's
        // sessions, cache and queue, so it unlocks the panel rather than one
        // customer's account. A viewer can already see that a password exists
        // and cannot act on the value, so it does not travel.
        fakeRedisWithPassword();

        $viewer = User::factory()->create();
        grantPermission($viewer, 'setting', view: true, manage: false);

        $this->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
            ->getJson('/api/settings')->assertOk()
            ->assertJsonPath('settings.redis.has_password', true)
            ->assertJsonPath('settings.redis.password', null);
    });

    it('sends null when no password is set, not an empty string', function () {
        // `has_password: false` is the answer; an empty string in a password
        // field renders as a password that is blank.
        fakeRedisWithPassword('');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/settings')->assertOk()
            ->assertJsonPath('settings.redis.has_password', false)
            ->assertJsonPath('settings.redis.password', null);
    });
});
