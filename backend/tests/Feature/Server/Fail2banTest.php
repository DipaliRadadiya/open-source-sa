<?php

use App\Jobs\InstallFail2ban;
use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->jailD = sys_get_temp_dir().'/sv-oss-f2b-'.getmypid();
    File::deleteDirectory($this->jailD);
    File::makeDirectory($this->jailD, 0755, true);

    config(['server.fail2ban.jail_d' => $this->jailD]);
});

afterEach(fn () => File::deleteDirectory($this->jailD));

/**
 * Fake fail2ban-client. `$installed` false makes `which` fail, which is what
 * a server that has never had fail2ban looks like.
 *
 * @param  array<string, array<int, string>>  $bans  jail => banned IPs
 */
function fakeFail2ban(bool $installed = true, array $bans = ['sshd' => []], bool $running = true): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs, $installed, $bans, $running) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];
        $command = $process->command;

        if ($command[0] === 'which') {
            return Process::result(output: $installed ? "/usr/bin/fail2ban-client\n" : '', exitCode: $installed ? 0 : 1);
        }

        // Let `tee` do what tee does, so the config we write is the config
        // the next read parses — otherwise the round trip is never exercised.
        if ($command[0] === 'tee') {
            File::put($command[1], (string) $process->input);

            return Process::result(exitCode: 0);
        }

        if ($command[0] !== 'fail2ban-client') {
            return Process::result(exitCode: 0);
        }

        return match ($command[1] ?? '') {
            'ping' => Process::result(output: 'Server replied: pong', exitCode: $running ? 0 : 1),
            '--version' => Process::result(output: 'fail2ban-client v1.0.2'),
            'status' => count($command) === 2
                ? Process::result(output: "Status\n|- Jail list:\t".implode(', ', array_keys($bans))."\n")
                : Process::result(output: 'Status for the jail'),
            'get' => Process::result(output: "['".implode("', '", $bans[$command[2]] ?? [])."']"),
            default => Process::result(exitCode: 0),
        };
    });

    return $runs;
}

function f2b(string $method, string $uri, array $body = []): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->json($method, $uri, $body);
}

function dropIn(): string
{
    return (string) @file_get_contents(test()->jailD.'/panel.local');
}

it('reports honestly when fail2ban is not installed, instead of failing', function () {
    fakeFail2ban(installed: false);

    // The screen has to render on a server that has never had fail2ban —
    // that is the normal starting state, not an error.
    f2b('GET', '/api/fail2ban')
        ->assertOk()
        ->assertJsonPath('fail2ban.installed', false)
        ->assertJsonPath('fail2ban.settings', null)
        ->assertJsonPath('fail2ban.jails', []);
});

it('tells the caller its own IP, so it can be added to the ignore list', function () {
    fakeFail2ban();

    // Without this the operator has to go and find their own address before
    // they can safely turn on the SSH jail.
    f2b('GET', '/api/fail2ban')->assertOk()->assertJsonPath('fail2ban.your_ip', '127.0.0.1');
});

it('queues the install and refuses to install twice', function () {
    Queue::fake();
    fakeFail2ban(installed: false);

    f2b('POST', '/api/fail2ban/install')->assertStatus(202);
    Queue::assertPushed(InstallFail2ban::class);

    fakeFail2ban(installed: true);
    f2b('POST', '/api/fail2ban/install')->assertUnprocessable();
});

it('refuses to enable the SSH jail without an acknowledgement', function () {
    fakeFail2ban(bans: []);

    // This is the one action that can lock the operator out of their own
    // server. It stays possible — but not by accident.
    f2b('PUT', '/api/fail2ban', [
        'bantime' => 3600, 'findtime' => 600, 'maxretry' => 5,
        'jails' => ['sshd' => true],
    ])->assertUnprocessable();

    expect(dropIn())->toBe('');
});

it('enables the SSH jail once the risk is acknowledged', function () {
    fakeFail2ban(bans: []);

    f2b('PUT', '/api/fail2ban', [
        'bantime' => 3600, 'findtime' => 600, 'maxretry' => 5,
        'jails' => ['sshd' => true], 'acknowledged' => true,
    ])->assertOk();

    expect(dropIn())->toContain("[sshd]\nenabled = true");
});

it('enables the SSH jail without acknowledgement when the caller is already ignored', function () {
    fakeFail2ban(bans: []);

    // Their address is on the ignore list, so the lockout this guards against
    // cannot happen to them.
    f2b('PUT', '/api/fail2ban', [
        'bantime' => 3600, 'findtime' => 600, 'maxretry' => 5,
        'ignore_ips' => ['127.0.0.1'],
        'jails' => ['sshd' => true],
    ])->assertOk();

    expect(dropIn())->toContain("[sshd]\nenabled = true");
});

it('always ignores loopback, whatever the user submits', function () {
    fakeFail2ban(bans: []);

    f2b('PUT', '/api/fail2ban', [
        'bantime' => 3600, 'findtime' => 600, 'maxretry' => 5,
        'ignore_ips' => ['203.0.113.5'],
    ])->assertOk();

    // Banning the machine from itself helps nobody, and the user did not ask
    // for these — so they are not theirs to remove either.
    expect(dropIn())->toContain('ignoreip = 127.0.0.1/8 ::1 203.0.113.5');

    f2b('GET', '/api/fail2ban')->assertJsonPath('fail2ban.settings.ignore_ips', ['203.0.113.5']);
});

it('writes to a jail.d drop-in and never touches jail.local', function () {
    fakeFail2ban(bans: []);

    f2b('PUT', '/api/fail2ban', ['bantime' => 7200, 'findtime' => 900, 'maxretry' => 3])->assertOk();

    // A server migrated from another panel probably owns jail.local already.
    expect(File::exists("{$this->jailD}/panel.local"))->toBeTrue()
        ->and(dropIn())->toContain('bantime = 7200')
        ->and(dropIn())->toContain('backend = systemd');
});

it('reloads rather than restarts, so active bans survive a settings change', function () {
    $runs = fakeFail2ban(bans: []);

    f2b('PUT', '/api/fail2ban', ['bantime' => 3600, 'findtime' => 600, 'maxretry' => 5])->assertOk();

    $commands = collect($runs)->pluck('command');

    // A restart forgets every ban — quietly undoing the protection the user
    // came here to configure.
    expect($commands)->toContain(['fail2ban-client', 'reload'])
        ->and($commands)->not->toContain(['systemctl', 'restart', 'fail2ban']);
});

it('does not silently disable a jail the request did not mention', function () {
    // sshd is already running; the request only talks about recidive.
    fakeFail2ban(bans: ['sshd' => [], 'recidive' => []]);

    f2b('PUT', '/api/fail2ban', [
        'bantime' => 3600, 'findtime' => 600, 'maxretry' => 5,
        'jails' => ['recidive' => true],
    ])->assertOk();

    // The file is rewritten whole, so an unmentioned jail must keep its
    // current state rather than defaulting to off.
    expect(dropIn())->toContain("[sshd]\nenabled = true");
});

it('lists who is banned, across every jail', function () {
    fakeFail2ban(bans: ['sshd' => ['203.0.113.5'], 'recidive' => ['198.51.100.9']]);

    f2b('GET', '/api/fail2ban')
        ->assertOk()
        ->assertJsonPath('fail2ban.banned.0.ip', '203.0.113.5')
        ->assertJsonPath('fail2ban.banned.0.jail', 'sshd')
        ->assertJsonPath('fail2ban.banned.1.ip', '198.51.100.9')
        ->assertJsonPath('fail2ban.banned.1.jail', 'recidive');
});

it('unbans an address from every jail holding it', function () {
    $runs = fakeFail2ban(bans: ['sshd' => ['203.0.113.5'], 'recidive' => ['203.0.113.5']]);

    f2b('DELETE', '/api/fail2ban/bans/203.0.113.5')
        ->assertOk()
        ->assertJsonPath('unbanned.jails', ['sshd', 'recidive']);

    // "Unban" means the address can connect again — not that it stays banned
    // by the jail the user wasn't looking at.
    $commands = collect($runs)->pluck('command');
    expect($commands)->toContain(['fail2ban-client', 'set', 'sshd', 'unbanip', '203.0.113.5'])
        ->and($commands)->toContain(['fail2ban-client', 'set', 'recidive', 'unbanip', '203.0.113.5']);
});

it('reports unbanning an address that is not banned', function () {
    fakeFail2ban(bans: ['sshd' => []]);

    // Agreeing with a stale list is how stale lists survive.
    f2b('DELETE', '/api/fail2ban/bans/203.0.113.5')->assertNotFound();
});

it('bans an address by hand and records it', function () {
    $runs = fakeFail2ban(bans: ['sshd' => []]);

    f2b('POST', '/api/fail2ban/bans', ['ip' => '203.0.113.5', 'jail' => 'sshd'])->assertOk();

    expect(collect($runs)->pluck('command'))
        ->toContain(['fail2ban-client', 'set', 'sshd', 'banip', '203.0.113.5']);

    $entry = ActivityLog::where('type', 'fail2ban')->where('action', 'ip_banned')->first();
    expect($entry->properties['ip'])->toBe('203.0.113.5');
});

it('refuses to ban an address that is on the ignore list', function () {
    fakeFail2ban(bans: ['sshd' => []]);
    File::put("{$this->jailD}/panel.local", "[DEFAULT]\nignoreip = 127.0.0.1/8 ::1 203.0.113.5\n");

    // fail2ban would drop the ban at the next reload, so accepting it here
    // would be promising something that quietly stops being true.
    f2b('POST', '/api/fail2ban/bans', ['ip' => '203.0.113.5', 'jail' => 'sshd'])->assertUnprocessable();
});

it('rejects a malformed address or an unknown jail', function () {
    fakeFail2ban(bans: ['sshd' => []]);

    f2b('POST', '/api/fail2ban/bans', ['ip' => 'not-an-ip', 'jail' => 'sshd'])
        ->assertUnprocessable()->assertJsonValidationErrors('ip');

    f2b('POST', '/api/fail2ban/bans', ['ip' => '203.0.113.5', 'jail' => 'made-up'])
        ->assertUnprocessable()->assertJsonValidationErrors('jail');

    f2b('PUT', '/api/fail2ban', [
        'bantime' => 3600, 'findtime' => 600, 'maxretry' => 5,
        'ignore_ips' => ['nonsense'],
    ])->assertUnprocessable();
});

it('rejects a ban time too short to mean anything', function () {
    fakeFail2ban(bans: []);

    f2b('PUT', '/api/fail2ban', ['bantime' => 5, 'findtime' => 600, 'maxretry' => 5])
        ->assertUnprocessable()->assertJsonValidationErrors('bantime');

    // One failed login is a typo, not an attack.
    f2b('PUT', '/api/fail2ban', ['bantime' => 3600, 'findtime' => 600, 'maxretry' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('maxretry');
});

it('denies a user with view-only access', function () {
    fakeFail2ban();
    $user = User::factory()->create();
    grantPermission($user, 'fail2ban', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/fail2ban')->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/fail2ban', ['bantime' => 3600, 'findtime' => 600, 'maxretry' => 5])
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/fail2ban/bans/203.0.113.5')->assertForbidden();
});

it('denies a user with no fail2ban permission at all', function () {
    fakeFail2ban();
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/fail2ban')->assertForbidden();
});

it('bans on the port SSH is actually listening on, not the default', function () {
    fakeFail2ban(bans: []);

    $sshd = sys_get_temp_dir().'/sv-oss-sshd-'.getmypid();
    File::deleteDirectory($sshd);
    File::makeDirectory($sshd, 0755, true);
    File::put("{$sshd}/60-panel.conf", "Port 2222\nPermitRootLogin no\n");
    config(['server.sshd_config_dir' => $sshd]);

    f2b('PUT', '/api/fail2ban', [
        'bantime' => 3600, 'findtime' => 600, 'maxretry' => 5,
        'jails' => ['sshd' => true], 'acknowledged' => true,
    ])->assertOk();

    // fail2ban's own default is `port = ssh`, i.e. 22. On a server whose SSH
    // this panel moved, that bans a port nobody is using while the screen
    // says the server is protected.
    expect(dropIn())->toContain('port = 2222')
        ->and(dropIn())->not->toContain('port = ssh');

    File::deleteDirectory($sshd);
});

it('falls back to the default SSH port when nothing has changed it', function () {
    fakeFail2ban(bans: []);
    config(['server.sshd_config_dir' => '/nonexistent-sshd-dir']);

    f2b('PUT', '/api/fail2ban', [
        'bantime' => 3600, 'findtime' => 600, 'maxretry' => 5,
        'jails' => ['sshd' => true], 'acknowledged' => true,
    ])->assertOk();

    expect(dropIn())->toContain('port = 22');
});

it('reports how hard a jail is being hit right now', function () {
    Process::fake(function ($process) {
        $command = $process->command;

        if ($command[0] === 'which') {
            return Process::result(output: "/usr/bin/fail2ban-client\n");
        }

        return match (true) {
            ($command[1] ?? '') === 'ping' => Process::result(output: 'pong'),
            $command === ['fail2ban-client', 'status'] => Process::result(output: "|- Jail list:\tsshd\n"),
            ($command[1] ?? '') === 'status' => Process::result(output: "Status for the jail: sshd\n|- Filter\n|  |- Currently failed:\t3\n|  `- Total failed:\t1847\n"
                ."`- Actions\n   |- Currently banned:\t2\n   `- Total banned:\t42\n"),
            default => Process::result(output: "['203.0.113.5']"),
        };
    });

    // "Currently failed" is the number that says an attack is happening now,
    // as opposed to the totals, which only say one happened at some point.
    f2b('GET', '/api/fail2ban')
        ->assertOk()
        ->assertJsonPath('fail2ban.jails.0.stats.currently_failed', 3)
        ->assertJsonPath('fail2ban.jails.0.stats.total_failed', 1847)
        ->assertJsonPath('fail2ban.jails.0.stats.total_banned', 42);
});

it('says how long each ban has left to run', function () {
    $expires = date('Y-m-d H:i:s', time() + 1800);
    $started = date('Y-m-d H:i:s', time() - 1800);

    Process::fake(function ($process) use ($started, $expires) {
        $command = $process->command;

        if ($command[0] === 'which') {
            return Process::result(output: "/usr/bin/fail2ban-client\n");
        }

        return match (true) {
            ($command[1] ?? '') === 'ping' => Process::result(output: 'pong'),
            $command === ['fail2ban-client', 'status'] => Process::result(output: "|- Jail list:\tsshd\n"),
            in_array('--with-time', $command, true) => Process::result(output: "203.0.113.5 \t{$started} + 3600 = {$expires}\n"),
            ($command[1] ?? '') === 'get' => Process::result(output: "['203.0.113.5']"),
            default => Process::result(output: ''),
        };
    });

    // A list of bare addresses doesn't tell you whether to wait or to unban.
    $ban = f2b('GET', '/api/fail2ban')->json('fail2ban.banned.0');

    expect($ban['ip'])->toBe('203.0.113.5')
        ->and($ban['expires_at'])->toBe($expires)
        ->and($ban['seconds_left'])->toBeGreaterThan(1700)
        ->and($ban['seconds_left'])->toBeLessThanOrEqual(1800);
});

it('leaves the timings out rather than guessing when fail2ban cannot report them', function () {
    // An older fail2ban answers `--with-time` with plain addresses.
    fakeFail2ban(bans: ['sshd' => ['203.0.113.5']]);

    $ban = f2b('GET', '/api/fail2ban')->json('fail2ban.banned.0');

    expect($ban['ip'])->toBe('203.0.113.5')
        ->and($ban['expires_at'])->toBeNull()
        ->and($ban['seconds_left'])->toBeNull();
});

it('releases every ban at once', function () {
    $runs = fakeFail2ban(bans: ['sshd' => ['203.0.113.5', '198.51.100.9']]);

    f2b('DELETE', '/api/fail2ban/bans')
        ->assertOk()
        ->assertJsonPath('unbanned.ips', ['203.0.113.5', '198.51.100.9']);

    $commands = collect($runs)->pluck('command');
    expect($commands)->toContain(['fail2ban-client', 'set', 'sshd', 'unbanip', '203.0.113.5'])
        ->and($commands)->toContain(['fail2ban-client', 'set', 'sshd', 'unbanip', '198.51.100.9']);
});

it('reports unban-all when there is nothing banned', function () {
    fakeFail2ban(bans: ['sshd' => []]);

    f2b('DELETE', '/api/fail2ban/bans')->assertNotFound();
});

it('offers ban-time presets so the form need not ask for seconds', function () {
    fakeFail2ban();

    $presets = f2b('GET', '/api/fail2ban')->json('fail2ban.bantime_presets');

    // Backend-driven for the same reason as the cron schedule presets: the
    // frontend should not keep its own copy of a list that has to agree with
    // what this endpoint accepts.
    expect(collect($presets)->pluck('key')->all())->toBe(['10m', '1h', '1d', '1w', 'permanent'])
        ->and(collect($presets)->firstWhere('key', '1h')['seconds'])->toBe(3600)
        ->and(collect($presets)->firstWhere('key', '1h')['label'])->toBe('1 hour')
        // fail2ban's permanent ban.
        ->and(collect($presets)->firstWhere('key', 'permanent')['seconds'])->toBe(-1);
});

it('accepts a permanent ban time but not a uselessly short one', function () {
    fakeFail2ban(bans: []);

    f2b('PUT', '/api/fail2ban', ['bantime' => -1, 'findtime' => 600, 'maxretry' => 5])->assertOk();
    expect(dropIn())->toContain('bantime = -1');

    // Anything under a minute expires before it inconveniences anyone, and
    // only looks like protection.
    f2b('PUT', '/api/fail2ban', ['bantime' => 30, 'findtime' => 600, 'maxretry' => 5])
        ->assertUnprocessable()->assertJsonValidationErrors('bantime');
});

it('denies unban-all to a view-only user', function () {
    fakeFail2ban(bans: ['sshd' => ['203.0.113.5']]);
    $user = User::factory()->create();
    grantPermission($user, 'fail2ban', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/fail2ban/bans')->assertForbidden();
});
