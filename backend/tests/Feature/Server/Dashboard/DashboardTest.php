<?php

use App\Models\ServerMetric;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // /proc fixtures + a fixture os-release; interval 0 so no sleep + no delta.
    $this->proc = sys_get_temp_dir().'/sv-oss-proc-'.uniqid();
    File::ensureDirectoryExists($this->proc.'/net');
    File::put($this->proc.'/loadavg', "0.50 1.20 2.00 1/234 5678\n");
    File::put($this->proc.'/meminfo', "MemTotal:        8000000 kB\nMemAvailable:    4000000 kB\nSwapTotal:       2000000 kB\nSwapFree:        1500000 kB\n");
    File::put($this->proc.'/stat', "cpu  100 0 100 800 0 0 0 0 0 0\ncpu0 50 0 50 400 0 0 0 0 0 0\n");
    File::put($this->proc.'/net/dev', "Inter-|   Receive\n face |bytes\n    lo: 999 0 0 0 0 0 0 0 999 0\n  eth0: 1000 0 0 0 0 0 0 0 2000 0\n");
    File::put($this->proc.'/uptime', "12345.67 40000.00\n");
    File::put($this->proc.'/cpuinfo', "processor\t: 0\nmodel name\t: Test CPU @ 3GHz\nprocessor\t: 1\nmodel name\t: Test CPU @ 3GHz\n");
    File::put($this->proc.'/os-release', "PRETTY_NAME=\"Ubuntu 24.04 LTS\"\nID=ubuntu\n");

    config([
        'server.proc_dir' => $this->proc,
        'server.os_release' => $this->proc.'/os-release',
        'server.metrics.sample_interval' => 0,
        'server.reboot_required_file' => $this->proc.'/reboot-required',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->proc));

function fakeDashboard(): void
{
    Process::fake(function ($process) {
        $cmd = $process->command;

        return match (true) {
            ($cmd[0] ?? '') === 'df' => Process::result(output: "fs 1B-blocks Used Avail Cap Mount\n/dev/vda1 100000000000 60000000000 40000000000 60% /\n"),
            $cmd === ['hostname', '--fqdn'] => Process::result(output: 'server.example'),
            $cmd === ['hostname', '-I'] => Process::result(output: '1.2.3.4 10.0.0.5'),
            $cmd === ['uname', '-r'] => Process::result(output: '6.8.0-generic'),
            $cmd === ['uname', '-m'] => Process::result(output: 'x86_64'),
            ($cmd[0] ?? '') === 'timedatectl' => Process::result(output: 'Etc/UTC'),
            ($cmd[0] ?? '') === 'php' => Process::result(output: '8.4.1'),
            ($cmd[0] ?? '') === 'node' => Process::result(output: 'v20.11.0'),
            ($cmd[0] ?? '') === 'nginx' => Process::result(errorOutput: 'nginx version: nginx/1.24.0'),
            ($cmd[0] ?? '') === 'redis-server' => Process::result(output: 'Redis server v=7.2.4 sha=0'),
            ($cmd[0] ?? '') === 'mysql' => Process::result(output: 'mysql  Ver 8.0.36 for Linux'),
            ($cmd[0] ?? '') === 'ps' => Process::result(output: "  PID USER  %CPU %MEM COMMAND\n 1234 root   5.0  2.1 nginx\n 5678 mysql  3.0 10.5 mysqld\n"),
            default => Process::result(exitCode: 0),
        };
    });
}

it('returns server facts', function () {
    fakeDashboard();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/server/facts')->assertOk()
        ->assertJsonPath('facts.os', 'Ubuntu 24.04 LTS')
        ->assertJsonPath('facts.hostname', 'server.example')
        ->assertJsonPath('facts.ip', '1.2.3.4')
        ->assertJsonPath('facts.cpu.cores', 2)
        ->assertJsonPath('facts.timezone', 'Etc/UTC')
        ->assertJsonPath('facts.reboot_required', false)
        ->assertJsonPath('facts.runtimes.php', '8.4.1')
        ->assertJsonPath('facts.runtimes.node', '20.11.0');
});

it('returns a live metric breakdown (total/used/free/percent) from /proc', function () {
    fakeDashboard();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/server/metrics/live')->assertOk()
        // memory: 8000000 kB total, 4000000 kB available → 50%, values in bytes
        ->assertJsonPath('metrics.memory.total', 8192000000)
        ->assertJsonPath('metrics.memory.used', 4096000000)
        ->assertJsonPath('metrics.memory.free', 4096000000)
        ->assertJsonPath('metrics.memory.percent', 50)
        ->assertJsonPath('metrics.swap.percent', 25)
        // disk from df -B1: total/used/free bytes + percent
        ->assertJsonPath('metrics.disk.total', 100000000000)
        ->assertJsonPath('metrics.disk.used', 60000000000)
        ->assertJsonPath('metrics.disk.free', 40000000000)
        ->assertJsonPath('metrics.disk.percent', 60)
        ->assertJsonPath('metrics.cpu.cores', 2)
        ->assertJsonPath('metrics.load.15', 2)
        ->assertJsonStructure(['metrics' => [
            'cpu' => ['percent', 'cores'],
            'memory' => ['total', 'used', 'free', 'percent', 'used_human'],
            'disk' => ['total', 'used', 'free', 'percent'],
            'network' => ['in', 'out', 'in_human', 'out_human'],
        ]]);
});

it('returns the server process table', function () {
    fakeDashboard();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/server/processes')->assertOk()
        ->assertJsonPath('processes.0.pid', 1234)
        ->assertJsonPath('processes.0.user', 'root')
        ->assertJsonPath('processes.0.command', 'nginx')
        ->assertJsonPath('processes.1.command', 'mysqld');
});

it('samples metrics into the table and prunes old rows', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
    fakeDashboard();

    // An old row (2 days ago) that must be pruned.
    ServerMetric::create(['cpu_percent' => 1, 'memory_percent' => 1, 'swap_percent' => 0, 'disk_percent' => 1,
        'load_1' => 0, 'load_5' => 0, 'load_15' => 0, 'net_in' => 0, 'net_out' => 0,
        'sampled_at' => Carbon::parse('2026-07-25 12:00:00')]);

    $this->artisan('server:sample-metrics')->assertExitCode(0);

    expect(ServerMetric::query()->count())->toBe(1); // old pruned, new one added
    $row = ServerMetric::query()->first();
    expect((float) $row->memory_percent)->toBe(50.0);
    expect($row->sampled_at->toDateTimeString())->toBe('2026-07-27 12:00:00');

    Carbon::setTestNow();
});

it('returns 24h history for the charts', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
    ServerMetric::create(['cpu_percent' => 10, 'memory_percent' => 40, 'swap_percent' => 0, 'disk_percent' => 55,
        'load_1' => 0.3, 'load_5' => 0.4, 'load_15' => 0.5, 'net_in' => 1000, 'net_out' => 2000,
        'sampled_at' => Carbon::parse('2026-07-27 11:00:00')]);
    ServerMetric::create(['cpu_percent' => 1, 'memory_percent' => 1, 'swap_percent' => 0, 'disk_percent' => 1,
        'load_1' => 0, 'load_5' => 0, 'load_15' => 0, 'net_in' => 0, 'net_out' => 0,
        'sampled_at' => Carbon::parse('2026-07-25 11:00:00')]); // older than 24h → excluded

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/server/metrics/history')->assertOk()
        ->assertJsonCount(1, 'metrics')
        ->assertJsonPath('metrics.0.cpu', 10)
        ->assertJsonPath('metrics.0.load_15', 0.5)
        ->assertJsonPath('metrics.0.net_out', 2000);

    Carbon::setTestNow();
});

it('denies a user without the dashboard permission', function () {
    fakeDashboard();
    $stranger = User::factory()->create();
    $token = $stranger->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/server/facts')->assertForbidden();
});
