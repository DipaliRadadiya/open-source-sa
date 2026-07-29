<?php

use App\Models\ServerMetric;
use App\Models\User;
use App\Services\Server\Metrics\ServerMetrics;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->base = sys_get_temp_dir().'/sv-oss-diskio-'.getmypid();
    File::deleteDirectory($this->base);
    File::makeDirectory("{$this->base}/proc", 0755, true);
    // Whole disks only — partitions do not appear here, which is the whole
    // point of checking against it.
    foreach (['sda', 'loop0', 'sr0'] as $device) {
        File::makeDirectory("{$this->base}/sys-block/{$device}", 0755, true);
    }

    File::put("{$this->base}/proc/loadavg", "0.1 0.2 0.3 1/100 1234\n");
    File::put("{$this->base}/proc/stat", "cpu  100 0 100 800 0 0 0 0 0 0\n");
    File::makeDirectory("{$this->base}/proc/net", 0755, true);
    File::put("{$this->base}/proc/net/dev", '');

    config([
        'server.proc_dir' => "{$this->base}/proc",
        'server.sys_block' => "{$this->base}/sys-block",
        'server.metrics.sample_interval' => 0,
    ]);
});

afterEach(fn () => File::deleteDirectory($this->base));

/**
 * A /proc/diskstats line. Field 4 = reads completed, 6 = sectors read,
 * 8 = writes completed, 10 = sectors written. Sectors are always 512 bytes.
 */
function diskstatsLine(string $device, int $readOps, int $readSectors, int $writeOps, int $writeSectors): string
{
    return "   8       0 {$device} {$readOps} 0 {$readSectors} 0 {$writeOps} 0 {$writeSectors} 0 0 0 0 0 0 0 0\n";
}

function writeDiskstats(string $content): void
{
    File::put(test()->base.'/proc/diskstats', $content);
}

it('reports a rate, not the counter, so it does not grow with uptime', function () {
    Process::fake(['*' => Process::result(output: '')]);

    // A machine that has read 1 GB and written 512 MB since boot.
    writeDiskstats(diskstatsLine('sda', 500_000, 2_097_152, 200_000, 1_048_576));

    $io = app(ServerMetrics::class)->live()['disk_io'];

    // /proc/diskstats counters only ever climb. Both reads land in the same
    // idle instant here, so the honest answer is zero — reporting the raw
    // counter would show a gigabyte of "current" traffic on a silent disk,
    // and would keep climbing forever.
    expect($io['read'])->toBe(0)
        ->and($io['write'])->toBe(0)
        ->and($io['read_ops'])->toBe(0)
        ->and($io['read_human'])->toBe('0 B/s');
});

it('converts sectors to bytes at 512 bytes each', function () {
    writeDiskstats(diskstatsLine('sda', 7, 2048, 3, 1024));

    // diskstats reports 512-byte sectors regardless of the device's own
    // sector size — reading the hardware value here would be wrong on a 4K
    // drive.
    $totals = (fn () => $this->diskTotals())->call(app(ServerMetrics::class));

    expect($totals['read'])->toBe(1_048_576)      // 2048 × 512 = 1 MB
        ->and($totals['write'])->toBe(524_288)    // 1024 × 512 = 512 KB
        ->and($totals['read_ops'])->toBe(7)
        ->and($totals['write_ops'])->toBe(3);
});

it('counts a whole disk once, not again through each of its partitions', function () {
    Process::fake(['*' => Process::result(output: '')]);

    writeDiskstats(
        diskstatsLine('sda', 100, 2000, 50, 1000)
        .diskstatsLine('sda1', 60, 1200, 30, 600)
        .diskstatsLine('sda14', 20, 400, 10, 200)
        .diskstatsLine('sda15', 20, 400, 10, 200)
    );

    // Only `sda` is in /sys/block; the partitions report the same traffic
    // again, so summing every line would report it two or three times over.
    $totals = (fn () => $this->diskTotals())->call(app(ServerMetrics::class));

    expect($totals['read'])->toBe(2000 * 512)
        ->and($totals['write'])->toBe(1000 * 512)
        ->and($totals['read_ops'])->toBe(100);
});

it('ignores loop and optical devices', function () {
    writeDiskstats(
        diskstatsLine('sda', 10, 100, 5, 50)
        .diskstatsLine('loop0', 999, 99999, 999, 99999)
        .diskstatsLine('sr0', 500, 50000, 0, 0)
    );

    // Loop devices are mounted files, not hardware; sr0 is the optical drive.
    // Both are in /sys/block, so the type filter is what excludes them.
    $totals = (fn () => $this->diskTotals())->call(app(ServerMetrics::class));

    expect($totals['read'])->toBe(100 * 512)
        ->and($totals['read_ops'])->toBe(10);
});

it('skips a device-mapper node, whose traffic is already counted underneath', function () {
    File::makeDirectory("{$this->base}/sys-block/dm-0", 0755, true);

    writeDiskstats(
        diskstatsLine('sda', 10, 100, 5, 50)
        .diskstatsLine('dm-0', 10, 100, 5, 50)
    );

    $totals = (fn () => $this->diskTotals())->call(app(ServerMetrics::class));

    // On an LVM box dm-0 maps onto sda — counting both doubles everything.
    expect($totals['read'])->toBe(100 * 512);
});

it('reports zeros rather than failing when diskstats is unavailable', function () {
    Process::fake(['*' => Process::result(output: '')]);
    File::delete("{$this->base}/proc/diskstats");

    $io = app(ServerMetrics::class)->live()['disk_io'];

    expect($io['read'])->toBe(0)
        ->and($io['write'])->toBe(0)
        ->and($io['read_ops'])->toBe(0);
});

it('stores disk throughput in the 5-minute sample for the history chart', function () {
    Process::fake(['*' => Process::result(output: '')]);
    writeDiskstats(diskstatsLine('sda', 10, 100, 5, 50));

    $snapshot = app(ServerMetrics::class)->snapshot();

    expect($snapshot)->toHaveKeys(['disk_read', 'disk_write']);

    ServerMetric::create([...$snapshot, 'sampled_at' => now()]);

    $stored = ServerMetric::first();
    expect($stored->disk_read)->toBeInt()
        ->and($stored->disk_write)->toBeInt();
});

it('returns the new fields through the dashboard endpoints', function () {
    Process::fake(['*' => Process::result(output: '')]);
    writeDiskstats(diskstatsLine('sda', 10, 100, 5, 50));

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/server/metrics/live')
        ->assertOk()
        ->assertJsonStructure(['metrics' => ['disk_io' => [
            'read', 'write', 'read_human', 'write_human', 'read_ops', 'write_ops',
        ]]]);

    ServerMetric::create([...app(ServerMetrics::class)->snapshot(), 'sampled_at' => now()]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/server/metrics/history')
        ->assertOk()
        ->assertJsonStructure(['metrics' => [['disk_read', 'disk_write']]]);
});

it('does not sleep on the live endpoint, and measures against the previous poll', function () {
    Process::fake(['*' => Process::result(output: '')]);
    Cache::flush();

    writeDiskstats(diskstatsLine('sda', 100, 2048, 50, 1024));

    $started = microtime(true);
    $first = app(ServerMetrics::class)->live();
    $elapsed = microtime(true) - $started;

    // Sleeping once per counter is what made this endpoint cost three
    // seconds — enough that a few open dashboards exhaust the worker pool.
    expect($elapsed)->toBeLessThan(0.5);

    // Nothing to compare the first read against.
    expect($first['disk_io']['read'])->toBe(0);

    // Rewind the stored reading by ten seconds, then report 10 MB more read.
    $sample = Cache::get('server-metrics:counters');
    Cache::put('server-metrics:counters', ['counters' => $sample['counters'], 'at' => $sample['at'] - 10], 600);

    writeDiskstats(diskstatsLine('sda', 100, 2048 + 20480, 50, 1024));

    // 20480 sectors × 512 B = 10 MB over 10 s = 1 MB/s. Measured against the
    // real clock, so the window is 10 s plus the milliseconds this test took.
    expect(app(ServerMetrics::class)->live()['disk_io']['read'])
        ->toEqualWithDelta(1_048_576, 5_000);
});

it('ignores a previous reading too old to describe anything current', function () {
    Process::fake(['*' => Process::result(output: '')]);
    Cache::flush();

    writeDiskstats(diskstatsLine('sda', 100, 2048, 50, 1024));
    app(ServerMetrics::class)->live();

    // An hour-old reading would average across a window nobody is watching.
    $sample = Cache::get('server-metrics:counters');
    Cache::put('server-metrics:counters', ['counters' => $sample['counters'], 'at' => $sample['at'] - 3600], 600);

    writeDiskstats(diskstatsLine('sda', 100, 2048 + 20480, 50, 1024));

    expect(app(ServerMetrics::class)->live()['disk_io']['read'])->toBe(0);
});
