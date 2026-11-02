<?php

use App\Models\ServerCapability;
use App\Models\User;
use App\Services\Server\Settings\SystemUpdatesActivity;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * `GET /setup` → `setup.system_updates_running`: Ubuntu's own updates are
 * running, so a panel install will wait for them. Read without sudo from the
 * apt-daily units and the process list; the tests describe both.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->proc = sys_get_temp_dir().'/sv-proc-'.bin2hex(random_bytes(4));
    mkdir($this->proc);
    app()->instance(SystemUpdatesActivity::class, new SystemUpdatesActivity($this->proc));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->proc));
});

/** A process in the fake /proc, by its argv. */
function sysUpdatesFakeProcess(string $proc, int $pid, array $argv): void
{
    mkdir("{$proc}/{$pid}");
    file_put_contents("{$proc}/{$pid}/cmdline", implode("\0", $argv)."\0");
}

/** systemctl show answers with these ActiveStates, one per unit; anything else succeeds empty. */
function fakeAptUnits(string $daily, string $upgrade): void
{
    Process::fake(fn ($process) => ($process->command[0] ?? '') === 'systemctl' && ($process->command[1] ?? '') === 'show'
        ? Process::result(output: "{$daily}\n{$upgrade}\n")
        : Process::result(exitCode: 0));
}

function systemUpdatesRunning(): mixed
{
    return test()->withToken(test()->admin->createToken('t')->plainTextToken)
        ->getJson('/api/setup')->assertOk()->json('setup.system_updates_running');
}

it('is true while apt-daily-upgrade is running', function () {
    fakeAptUnits('inactive', 'activating');

    expect(systemUpdatesRunning())->toBeTrue();

    Process::assertRan(fn ($process) => $process->command === [
        'systemctl', 'show', 'apt-daily.service', 'apt-daily-upgrade.service', '--property=ActiveState', '--value',
    ]);
});

it('is true while apt-daily is downloading', function () {
    fakeAptUnits('activating', 'inactive');

    expect(systemUpdatesRunning())->toBeTrue();
});

it('is true while unattended-upgrade runs, whatever started it', function () {
    fakeAptUnits('inactive', 'inactive');
    sysUpdatesFakeProcess($this->proc, 4321, ['/usr/bin/python3', '/usr/bin/unattended-upgrade', '--verbose']);

    expect(systemUpdatesRunning())->toBeTrue();
});

it('is false on a quiet server, where only the shutdown helper idles', function () {
    fakeAptUnits('inactive', 'inactive');
    sysUpdatesFakeProcess($this->proc, 812, ['/usr/bin/python3', '/usr/share/unattended-upgrades/unattended-upgrade-shutdown', '--wait-for-signal']);
    sysUpdatesFakeProcess($this->proc, 900, ['/usr/sbin/nginx', '-g', 'daemon off;']);

    expect(systemUpdatesRunning())->toBeFalse();
});

it('is false, not an error, when systemctl cannot answer', function () {
    Process::fake(fn () => Process::result(errorOutput: 'Failed to connect to bus', exitCode: 1));

    expect(systemUpdatesRunning())->toBeFalse();
});

it('never asks through sudo', function () {
    fakeAptUnits('inactive', 'inactive');

    systemUpdatesRunning();

    Process::assertDidntRun(fn ($process) => in_array('sudo', (array) $process->command, true));
});
