<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Settings, as they behaved on a real OpenLiteSpeed server on 2026-09-23.
 *
 * Each test here is one thing that went wrong there. The fakes are built so
 * the bug is reproducible, not so the fix is easy: a swap request the disk
 * cannot hold, a server whose only keys are root's and a sudoer's, and an
 * apt configuration that says "on" in a file the panel did not write.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->dir = sys_get_temp_dir().'/sv-oss-live-'.uniqid();
    File::ensureDirectoryExists($this->dir);
    File::put($this->dir.'/meminfo', "SwapTotal:       1048572 kB\nSwapFree:        1048572 kB\n");
    File::put($this->dir.'/redis-cli', '');

    config([
        'server.sshd_config_dir' => $this->dir,
        'server.unattended_upgrades_file' => $this->dir.'/99-panel-upgrades',
        'server.reboot_required_file' => $this->dir.'/reboot-required',
        'server.redis_cli' => $this->dir.'/redis-cli',
        'server.proc_dir' => $this->dir,
        'server.swap_file' => $this->dir.'/swapfile',
        'server.fstab' => $this->dir.'/fstab',
        'server.swap_reserve_mb' => 1024,
    ]);

    $this->ran = new ArrayObject;
});

afterEach(fn () => File::deleteDirectory($this->dir));

/**
 * @param  array<string, callable>  $answers  binary => fn(array $cmd): ProcessResult
 */
function fakeLiveServer(array $answers): void
{
    $ran = test()->ran;

    Process::fake(function ($process) use ($answers, $ran) {
        $cmd = $process->command;

        if (($cmd[0] ?? null) === 'sudo' && ($cmd[1] ?? null) === '-n') {
            $cmd = array_slice($cmd, 2);
        }

        $ran[] = $cmd;

        return isset($answers[$cmd[0]]) ? $answers[$cmd[0]]($cmd) : Process::result();
    });
}

function liveRanBinaries(): array
{
    return array_map(fn (array $cmd) => $cmd[0].(isset($cmd[1]) ? ' '.$cmd[1] : ''), test()->ran->getArrayCopy());
}

function liveSettingsCall(string $method, string $uri, array $body = [])
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)->json($method, $uri, $body);
}

describe('swap', function () {
    it('refuses a size the disk cannot hold, before writing anything', function () {
        // 60 GB asked for with 51 GB free filled the root filesystem and left
        // a 54 GB file behind.
        fakeLiveServer([
            'df' => fn () => Process::result(output: "   Avail\n".(51 * 1024 ** 3)."\n"),
            'swapon' => fn () => Process::result(output: test()->dir."/swapfile\n"),
        ]);

        liveSettingsCall('PUT', '/api/settings/swap', ['size_mb' => 60000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('size_mb');

        expect(liveRanBinaries())->not->toContain('fallocate -l')
            ->and(collect(liveRanBinaries())->contains(fn ($c) => str_starts_with($c, 'swapoff')))->toBeFalse();
    });

    it('builds the new file before the old swap is switched off', function () {
        fakeLiveServer([
            'df' => fn () => Process::result(output: "Avail\n".(40 * 1024 ** 3)."\n"),
            'swapon' => fn () => Process::result(output: test()->dir."/swapfile\n"),
        ]);

        liveSettingsCall('PUT', '/api/settings/swap', ['size_mb' => 2048])->assertOk();

        $order = collect(liveRanBinaries());

        expect($order->search(fn ($c) => str_starts_with($c, 'mkswap')))
            ->toBeLessThan($order->search(fn ($c) => str_starts_with($c, 'swapoff')));
    });

    it('keeps the old swap on and removes the partial file when building fails', function () {
        // The live failure: swap already off, a half-allocated file left.
        fakeLiveServer([
            'df' => fn () => Process::result(output: "Avail\n".(40 * 1024 ** 3)."\n"),
            'swapon' => fn () => Process::result(output: test()->dir."/swapfile\n"),
            'fallocate' => fn () => Process::result(errorOutput: 'fallocate: fallocate failed: No space left on device', exitCode: 1),
        ]);

        liveSettingsCall('PUT', '/api/settings/swap', ['size_mb' => 2048])->assertServerError();

        $ran = collect(test()->ran->getArrayCopy())->map(fn ($c) => implode(' ', $c));

        expect($ran->contains(fn ($c) => str_starts_with($c, 'swapoff')))->toBeFalse()
            ->and($ran->last())->toBe('rm -f '.test()->dir.'/swapfile.new');
    });

    it('throws the new file away and keeps the old swap when swapoff is refused', function () {
        fakeLiveServer([
            'df' => fn () => Process::result(output: "Avail\n".(40 * 1024 ** 3)."\n"),
            'swapon' => fn () => Process::result(output: test()->dir."/swapfile\n"),
            'swapoff' => fn () => Process::result(errorOutput: 'swapoff: Cannot allocate memory', exitCode: 1),
        ]);

        liveSettingsCall('PUT', '/api/settings/swap', ['size_mb' => 2048])->assertUnprocessable();

        $ran = collect(test()->ran->getArrayCopy())->map(fn ($c) => implode(' ', $c));

        expect($ran->last())->toBe('rm -f '.test()->dir.'/swapfile.new')
            ->and($ran->contains('mv '.test()->dir.'/swapfile.new '.test()->dir.'/swapfile'))->toBeFalse();
    });
});

describe('the lockout guard', function () {
    function liveSshServer(array $keyFiles, string $sudoMembers = 'ubuntu'): void
    {
        fakeLiveServer([
            'sshd' => fn ($cmd) => Process::result(output: "port 22\npermitrootlogin prohibit-password\npasswordauthentication no\n"),
            // Only the real group answers — a fake that answered any group
            // name let a sabotaged lookup pass unnoticed.
            'getent' => fn ($cmd) => $cmd[1] === 'group'
                ? ($cmd[2] === 'sudo' ? Process::result(output: "sudo:x:27:{$sudoMembers}\n") : Process::result(exitCode: 2))
                : Process::result(output: "{$cmd[2]}:x:1000:1000::/home/{$cmd[2]}:/bin/bash\n"),
            'test' => fn ($cmd) => Process::result(exitCode: in_array(end($cmd), $keyFiles, true) ? 0 : 1),
            'ufw' => fn () => Process::result(output: "Status: inactive\n"),
        ]);
    }

    it("counts a sudoer's key — the account a cloud image hands you", function () {
        // Password login already off, a key for `ubuntu`: the screen refused
        // even to save the values it already had.
        liveSshServer(['/home/ubuntu/.ssh/authorized_keys']);

        liveSettingsCall('PUT', '/api/settings/security', ['port' => 22, 'permit_root_login' => 'no', 'password_authentication' => false])
            ->assertOk();
    });

    it("counts root's key while root login is allowed", function () {
        liveSshServer(['/root/.ssh/authorized_keys'], sudoMembers: '');

        liveSettingsCall('PUT', '/api/settings/security', ['port' => 22, 'permit_root_login' => 'prohibit-password', 'password_authentication' => false])
            ->assertOk();
    });

    it("does not count root's key when the same save turns root login off", function () {
        // A key for an account sshd will refuse is no way back in.
        liveSshServer(['/root/.ssh/authorized_keys'], sudoMembers: '');

        liveSettingsCall('PUT', '/api/settings/security', ['port' => 22, 'permit_root_login' => 'no', 'password_authentication' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password_authentication');
    });
});

describe('automatic security updates', function () {
    it('reports what apt will do, not only what the panel wrote', function () {
        // No panel file at all — a fresh server — while Ubuntu's own
        // 20auto-upgrades has them on. The screen said off.
        fakeLiveServer([
            'apt-config' => fn () => Process::result(output: "APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n"),
        ]);

        liveSettingsCall('GET', '/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.updates.security_updates_enabled', true);
    });
});
