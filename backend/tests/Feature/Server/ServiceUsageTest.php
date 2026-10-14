<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // A fake /proc so memory percentages don't depend on this machine: 8 GB.
    $this->procDir = sys_get_temp_dir().'/sv-oss-proc-'.getmypid();
    File::deleteDirectory($this->procDir);
    File::makeDirectory($this->procDir, 0755, true);
    File::put("{$this->procDir}/meminfo", "MemTotal:        8388608 kB\nMemFree:  1000 kB\n");

    // A fake cgroup mount, empty unless a test lays a unit's counters out.
    $this->cgroupDir = sys_get_temp_dir().'/sv-oss-cgroup-'.getmypid();
    File::deleteDirectory($this->cgroupDir);
    File::makeDirectory($this->cgroupDir, 0755, true);

    config(['server.proc_dir' => $this->procDir, 'server.cgroup_dir' => $this->cgroupDir]);
    Cache::flush();
});

afterEach(function () {
    File::deleteDirectory($this->procDir);
    File::deleteDirectory($this->cgroupDir);
});

/**
 * Lay out a unit's kernel counters the way cgroup v2 does.
 */
function cgroupCounters(string $controlGroup, array $files): void
{
    $dir = test()->cgroupDir.$controlGroup;
    File::makeDirectory($dir, 0755, true, true);

    foreach ($files as $name => $content) {
        File::put("{$dir}/{$name}", $content);
    }
}

/**
 * What OpenLiteSpeed's own lshttpd.service makes systemd say:
 * MemoryAccounting=false and TasksAccounting=false, CPU still counted.
 */
function olsState(array $overrides = []): string
{
    return unitState(array_merge([
        'MemoryCurrent' => '[not set]',
        'TasksCurrent' => '[not set]',
        'ControlGroup' => '/system.slice/lshttpd.service',
    ], $overrides));
}

/**
 * systemctl show output for a single unit.
 */
function unitState(array $overrides = []): string
{
    $properties = array_merge([
        'LoadState' => 'loaded',
        'ActiveState' => 'active',
        'UnitFileState' => 'enabled',
        'MemoryCurrent' => '419430400', // 400 MB
        'CPUUsageNSec' => '10000000000',
        'TasksCurrent' => '7',
    ], $overrides);

    return collect($properties)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n")."\n";
}

function servicesFor(string $only = 'nginx'): void
{
    config(['server.services' => [['key' => $only, 'unit' => $only, 'label' => ucfirst($only)]]]);
    // No php-fpm units, so exactly one service is described per request.
    config(['server.php_dir' => '/nonexistent-php-dir']);
}

function fetchServices(): array
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/services')->json('services.0');
}

it('reports memory usage as bytes, a human string and a share of total ram', function () {
    servicesFor();
    Process::fake(['*' => Process::result(output: unitState())]);

    $service = fetchServices();

    expect($service['usage']['memory_bytes'])->toBe(419430400)
        ->and($service['usage']['memory_human'])->toBe('400 MB')
        ->and($service['usage']['memory_percent'])->toBe(4.9)   // of 8 GB
        ->and($service['usage']['tasks'])->toBe(7);
});

it('reports no cpu percentage on the first read, rather than a wrong one', function () {
    servicesFor();
    Process::fake(['*' => Process::result(output: unitState())]);

    // CPUUsageNSec is cumulative since the unit started; with nothing to
    // compare against, any number we produced would be a lifetime average.
    expect(fetchServices()['usage']['cpu_percent'])->toBeNull();
});

it('computes the cpu percentage from the change since the previous read', function () {
    servicesFor();
    Process::fake(['*' => Process::result(output: unitState(['CPUUsageNSec' => '10000000000']))]);
    fetchServices();

    // Rewind the stored sample by exactly 10 seconds, then report 2 more
    // seconds of CPU: 2s of CPU over 10s of wall clock = 20% of one core.
    $key = 'service-usage:nginx';
    $sample = Cache::get($key);
    Cache::put($key, ['nsec' => $sample['nsec'], 'at' => $sample['at'] - 10], 300);

    Process::fake(['*' => Process::result(output: unitState(['CPUUsageNSec' => '12000000000']))]);

    expect(fetchServices()['usage']['cpu_percent'])->toEqual(20.0);
});

it('ignores a counter that went backwards, which means the service restarted', function () {
    servicesFor();
    Process::fake(['*' => Process::result(output: unitState(['CPUUsageNSec' => '10000000000']))]);
    fetchServices();

    $key = 'service-usage:nginx';
    $sample = Cache::get($key);
    Cache::put($key, ['nsec' => $sample['nsec'], 'at' => $sample['at'] - 10], 300);

    // A restart resets the counter — the delta would be nonsense.
    Process::fake(['*' => Process::result(output: unitState(['CPUUsageNSec' => '5000000']))]);

    expect(fetchServices()['usage']['cpu_percent'])->toBeNull();
});

it('reports no usage for a stopped service', function () {
    servicesFor();
    Process::fake(['*' => Process::result(output: unitState(['ActiveState' => 'inactive']))]);

    expect(fetchServices()['usage'])->toBeNull();
});

it('reports missing accounting as missing, not as zero', function () {
    servicesFor();
    // systemd says `[not set]` when accounting is disabled for the unit.
    Process::fake(['*' => Process::result(output: unitState([
        'MemoryCurrent' => '[not set]',
        'CPUUsageNSec' => '[not set]',
        'TasksCurrent' => '[not set]',
    ]))]);

    expect(fetchServices()['usage'])->toBeNull();
});

it('reads state and usage in a single systemctl call per service', function () {
    servicesFor();
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        if (($process->command[0] ?? null) === 'systemctl' && ($process->command[1] ?? null) === 'show') {
            $commands[] = $process->command;
        }

        return Process::result(output: unitState());
    });

    fetchServices();

    // Usage must not cost a second systemctl probe — it rides along on the
    // call the list was already making. Capability detection may run an
    // unrelated `which` probe on a server without a stored capability row.
    expect($commands)->toHaveCount(1)
        // `CanReload` rides along too: whether the panel offers Reload or only
        // Restart is decided from it (ServiceManager::allowedActions), and
        // asking systemd a second time for one boolean is the cost this test
        // exists to prevent.
        ->and($commands[0])->toContain('--property=Id,LoadState,ActiveState,UnitFileState,CanReload,MemoryCurrent,CPUUsageNSec,TasksCurrent,ControlGroup');
});

it('reads memory and tasks from the kernel counters when systemd has accounting off, as OpenLiteSpeed ships', function () {
    servicesFor('lshttpd');
    cgroupCounters('/system.slice/lshttpd.service', ['memory.current' => "534179840\n", 'pids.current' => "18\n"]);
    Process::fake(['*' => Process::result(output: olsState())]);

    $usage = fetchServices()['usage'];

    expect($usage['memory_bytes'])->toBe(534179840)
        ->and($usage['memory_human'])->toBe('509.4 MB')
        ->and($usage['memory_percent'])->toBe(6.2)   // of 8 GB
        ->and($usage['tasks'])->toBe(18);
});

it('prefers the figure systemd reports over the counter file', function () {
    servicesFor();
    // Disagreeing on purpose: if the file were read, the test would see it.
    cgroupCounters('/system.slice/nginx.service', ['memory.current' => '1', 'pids.current' => '1']);
    Process::fake(['*' => Process::result(output: unitState(['ControlGroup' => '/system.slice/nginx.service']))]);

    $usage = fetchServices()['usage'];

    expect($usage['memory_bytes'])->toBe(419430400)
        ->and($usage['tasks'])->toBe(7);
});

it('fills only the figure systemd left unset', function () {
    servicesFor('lshttpd');
    cgroupCounters('/system.slice/lshttpd.service', ['memory.current' => '1048576', 'pids.current' => '99']);
    Process::fake(['*' => Process::result(output: olsState(['TasksCurrent' => '4']))]);

    $usage = fetchServices()['usage'];

    expect($usage['memory_bytes'])->toBe(1048576)
        ->and($usage['tasks'])->toBe(4);
});

it('still reports memory as missing when the counter cannot be read', function (string $controlGroup, array $files) {
    servicesFor('lshttpd');
    cgroupCounters('/system.slice/lshttpd.service', $files);
    // A file outside the unit's directory that a bad path could reach.
    File::put(test()->cgroupDir.'/memory.current', '123456');
    Process::fake(['*' => Process::result(output: olsState(['ControlGroup' => $controlGroup]))]);

    $usage = fetchServices()['usage'];

    // CPU is still counted, so there is a usage block — with memory missing.
    expect($usage)->not->toBeNull()
        ->and($usage['memory_bytes'])->toBeNull()
        ->and($usage['memory_human'])->toBeNull()
        ->and($usage['tasks'])->toBeNull();
})->with([
    'no file' => ['/system.slice/lshttpd.service', []],
    'junk in the file' => ['/system.slice/lshttpd.service', ['memory.current' => 'max', 'pids.current' => '-1']],
    'empty file' => ['/system.slice/lshttpd.service', ['memory.current' => '', 'pids.current' => '']],
    'no control group (stopped)' => ['', ['memory.current' => '5', 'pids.current' => '5']],
    'the root cgroup' => ['/', ['memory.current' => '5', 'pids.current' => '5']],
    'steps out of the unit' => ['/system.slice/..', ['memory.current' => '5', 'pids.current' => '5']],
    'relative path' => ['system.slice/lshttpd.service', ['memory.current' => '5', 'pids.current' => '5']],
]);

it('gives a postgres-style summed service the fallback too', function () {
    config(['server.services' => [['key' => 'postgresql', 'unit' => 'postgresql', 'label' => 'PostgreSQL', 'instances' => 'postgresql@*']]]);
    config(['server.php_dir' => '/nonexistent-php-dir']);
    cgroupCounters('/system.slice/system-postgresql.slice/postgresql@18-main.service', ['memory.current' => '2097152', 'pids.current' => '3']);

    Process::fake(function ($process) {
        $command = $process->command;

        if (($command[1] ?? null) === 'list-units') {
            return Process::result(output: "postgresql@18-main.service loaded active running PostgreSQL Cluster 18-main\n");
        }

        return in_array('postgresql@18-main', $command, true) || in_array('postgresql@18-main.service', $command, true)
            ? Process::result(output: olsState(['ControlGroup' => '/system.slice/system-postgresql.slice/postgresql@18-main.service']))
            : Process::result(output: unitState(['MemoryCurrent' => '[not set]', 'TasksCurrent' => '[not set]']));
    });

    $usage = fetchServices()['usage'];

    expect($usage['memory_bytes'])->toBe(2097152)
        ->and($usage['tasks'])->toBe(3);
});
