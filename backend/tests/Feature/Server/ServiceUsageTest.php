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

    config(['server.proc_dir' => $this->procDir]);
    Cache::flush();
});

afterEach(fn () => File::deleteDirectory($this->procDir));

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
        ->and($commands[0])->toContain('--property=Id,LoadState,ActiveState,UnitFileState,CanReload,MemoryCurrent,CPUUsageNSec,TasksCurrent');
});
