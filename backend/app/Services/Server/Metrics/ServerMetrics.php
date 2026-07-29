<?php

namespace App\Services\Server\Metrics;

use App\Services\Server\ServerOps;
use App\Support\Bytes;

/**
 * Reads server facts + live metrics cheaply from `/proc` (+ df / small probes).
 * Everything is world-readable, so this works without root (detect-don't-trust).
 * CPU % and network rate need two reads a short interval apart (cumulative
 * counters).
 *
 * `snapshot()` = the flat percent fields stored for the 24h charts (the 5-min
 * collector). `live()` = a richer, UX-friendly breakdown (total/used/free +
 * percent per resource) for the live gauges — computed on demand, never stored.
 */
class ServerMetrics
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Flat percent sample stored per 5-min tick (drives the CPU/Mem/Disk/Load
     * history charts).
     *
     * @return array<string, int|float>
     */
    public function snapshot(): array
    {
        $memory = $this->memoryUsage();
        $swap = $this->swapUsage();
        $disk = $this->diskUsage();
        [$load1, $load5, $load15] = $this->load();
        [$netIn, $netOut] = $this->network();
        $io = $this->diskIo();

        return [
            'cpu_percent' => $this->cpuPercent(),
            'memory_percent' => $memory['percent'],
            'swap_percent' => $swap['percent'],
            'disk_percent' => $disk['percent'],
            'load_1' => $load1,
            'load_5' => $load5,
            'load_15' => $load15,
            'net_in' => $netIn,
            'net_out' => $netOut,
            'disk_read' => $io['read'],
            'disk_write' => $io['write'],
        ];
    }

    /**
     * Live snapshot for the dashboard gauges — each resource as
     * total / used / free / percent (+ human) so the UI can show a full
     * breakdown, not just a percentage. Poll this for live gauges + the
     * network stream chart.
     *
     * @return array<string, mixed>
     */
    public function live(): array
    {
        [$load1, $load5, $load15] = $this->load();
        [$netIn, $netOut] = $this->network();
        $io = $this->diskIo();

        return [
            'cpu' => ['percent' => $this->cpuPercent(), 'cores' => $this->cpuCores()],
            'memory' => $this->withHuman($this->memoryUsage()),
            'swap' => $this->withHuman($this->swapUsage()),
            'disk' => $this->withHuman($this->diskUsage()),
            'load' => ['1' => $load1, '5' => $load5, '15' => $load15],
            'network' => [
                'in' => $netIn,
                'out' => $netOut,
                'in_human' => Bytes::human($netIn).'/s',
                'out_human' => Bytes::human($netOut).'/s',
            ],
            // Throughput and IOPS: on a database server "the disk is slow"
            // usually means operations per second, not megabytes, and both
            // come off the same counters.
            'disk_io' => [
                'read' => $io['read'],
                'write' => $io['write'],
                'read_human' => Bytes::human($io['read']).'/s',
                'write_human' => Bytes::human($io['write']).'/s',
                'read_ops' => $io['read_ops'],
                'write_ops' => $io['write_ops'],
            ],
        ];
    }

    /**
     * Static-ish server facts for the dashboard info card.
     *
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        $memory = $this->memoryUsage();
        $disk = $this->diskUsage();

        return [
            'hostname' => $this->cmd(['hostname', '--fqdn']) ?: (string) gethostname(),
            'os' => $this->osName(),
            'kernel' => $this->cmd(['uname', '-r']),
            'arch' => $this->cmd(['uname', '-m']),
            'uptime' => $this->uptime(),
            'ip' => $this->primaryIp(),
            'cpu' => ['model' => $this->cpuModel(), 'cores' => $this->cpuCores()],
            'memory_total' => $memory['total'],
            'memory_total_human' => Bytes::human((int) $memory['total']),
            'disk_total' => $disk['total'],
            'disk_total_human' => Bytes::human((int) $disk['total']),
            'timezone' => $this->cmd(['timedatectl', 'show', '--property=Timezone', '--value']) ?: 'Etc/UTC',
            'reboot_required' => is_file((string) config('server.reboot_required_file', '/var/run/reboot-required')),
            'runtimes' => $this->runtimes(),
        ];
    }

    /**
     * Top processes by CPU (server process table).
     *
     * @return array<int, array<string, mixed>>
     */
    public function processes(): array
    {
        $limit = (int) config('server.metrics.processes_limit', 25);

        $output = $this->serverOps->run(
            ['ps', '-eo', 'pid,user:20,%cpu,%mem,comm', '--sort=-%cpu'],
            ['feature' => 'dashboard', 'op' => 'processes'],
        )->output();

        $processes = [];
        foreach (array_slice(preg_split('/\r?\n/', trim($output)) ?: [], 1) as $line) {
            $parts = preg_split('/\s+/', trim($line), 5);
            if (count($parts) < 5) {
                continue;
            }
            $processes[] = [
                'pid' => (int) $parts[0],
                'user' => $parts[1],
                'cpu' => (float) $parts[2],
                'memory' => (float) $parts[3],
                'command' => $parts[4],
            ];
            if (count($processes) >= $limit) {
                break;
            }
        }

        return $processes;
    }

    // ---- usage breakdowns (total / used / free / percent) ----

    /**
     * @return array{total: int, used: int, free: int, percent: float}
     */
    private function memoryUsage(): array
    {
        $m = $this->meminfo();
        $total = ($m['MemTotal'] ?? 0) * 1024;
        $available = ($m['MemAvailable'] ?? 0) * 1024;

        return $this->usage($total, max(0, $total - $available), $available);
    }

    /**
     * @return array{total: int, used: int, free: int, percent: float}
     */
    private function swapUsage(): array
    {
        $m = $this->meminfo();
        $total = ($m['SwapTotal'] ?? 0) * 1024;
        $free = ($m['SwapFree'] ?? 0) * 1024;

        return $this->usage($total, max(0, $total - $free), $free);
    }

    /**
     * @return array{total: int, used: int, free: int, percent: float}
     */
    private function diskUsage(): array
    {
        $output = $this->serverOps->run(
            ['df', '-B1', '-P', (string) config('server.disk_path', '/')],
            ['feature' => 'dashboard', 'op' => 'disk'],
        )->output();

        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($output)) ?: []));
        $row = preg_split('/\s+/', trim((string) end($lines))) ?: [];

        return $this->usage((int) ($row[1] ?? 0), (int) ($row[2] ?? 0), (int) ($row[3] ?? 0));
    }

    /**
     * @return array{total: int, used: int, free: int, percent: float}
     */
    private function usage(int $total, int $used, int $free): array
    {
        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percent' => $total > 0 ? round($used / $total * 100, 2) : 0.0,
        ];
    }

    /**
     * @param  array{total: int, used: int, free: int, percent: float}  $usage
     * @return array<string, int|float|string>
     */
    private function withHuman(array $usage): array
    {
        return [
            ...$usage,
            'total_human' => Bytes::human($usage['total']),
            'used_human' => Bytes::human($usage['used']),
            'free_human' => Bytes::human($usage['free']),
        ];
    }

    // ---- rate/instant metric helpers ----

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function load(): array
    {
        $parts = preg_split('/\s+/', trim($this->proc('loadavg')));

        return [(float) ($parts[0] ?? 0), (float) ($parts[1] ?? 0), (float) ($parts[2] ?? 0)];
    }

    private function cpuPercent(): float
    {
        [$total1, $idle1] = $this->cpuTimes();
        $this->tick();
        [$total2, $idle2] = $this->cpuTimes();

        $totalDelta = $total2 - $total1;
        $idleDelta = $idle2 - $idle1;

        return $totalDelta > 0 ? round(max(0, $totalDelta - $idleDelta) / $totalDelta * 100, 2) : 0.0;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function cpuTimes(): array
    {
        foreach (preg_split('/\r?\n/', $this->proc('stat')) ?: [] as $line) {
            if (str_starts_with($line, 'cpu ')) {
                $values = array_map('intval', array_slice(preg_split('/\s+/', trim($line)), 1));

                return [array_sum($values), ($values[3] ?? 0) + ($values[4] ?? 0)]; // total, idle+iowait
            }
        }

        return [0, 0];
    }

    /**
     * Disk throughput and IOPS, as a rate.
     *
     * @return array{read: int, write: int, read_ops: int, write_ops: int}
     */
    private function diskIo(): array
    {
        $first = $this->diskTotals();
        $this->tick();
        $second = $this->diskTotals();

        $seconds = max(1, (int) config('server.metrics.sample_interval', 1));

        return [
            'read' => (int) max(0, intdiv($second['read'] - $first['read'], $seconds)),
            'write' => (int) max(0, intdiv($second['write'] - $first['write'], $seconds)),
            'read_ops' => (int) max(0, intdiv($second['read_ops'] - $first['read_ops'], $seconds)),
            'write_ops' => (int) max(0, intdiv($second['write_ops'] - $first['write_ops'], $seconds)),
        ];
    }

    /**
     * Cumulative disk counters, summed over whole physical disks only.
     *
     * /proc/diskstats lists partitions alongside their parent disk and loop
     * devices alongside both, so summing every line counts the same traffic
     * two or three times over. Only whole devices (present in /sys/block) of a
     * real disk type are counted — which also skips the device-mapper nodes on
     * an LVM box, whose I/O is already counted on the disk underneath.
     *
     * @return array{read: int, write: int, read_ops: int, write_ops: int}
     */
    private function diskTotals(): array
    {
        $totals = ['read' => 0, 'write' => 0, 'read_ops' => 0, 'write_ops' => 0];

        foreach (preg_split('/\r?\n/', $this->proc('diskstats')) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line));

            if ($fields === false || count($fields) < 10) {
                continue;
            }

            if (! $this->isWholeDisk((string) $fields[2])) {
                continue;
            }

            // Sectors in diskstats are always 512 bytes, regardless of the
            // device's own sector size.
            $totals['read_ops'] += (int) $fields[3];
            $totals['read'] += (int) $fields[5] * 512;
            $totals['write_ops'] += (int) $fields[7];
            $totals['write'] += (int) $fields[9] * 512;
        }

        return $totals;
    }

    private function isWholeDisk(string $device): bool
    {
        $pattern = (string) config('server.metrics.disk_devices', '/^(sd|nvme|vd|xvd|hd|md)/');

        if (preg_match($pattern, $device) !== 1) {
            return false;
        }

        // /sys/block holds whole devices only — partitions live beneath them,
        // so this is what separates sda from sda1.
        return is_dir(rtrim((string) config('server.sys_block', '/sys/block'), '/')."/{$device}");
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function network(): array
    {
        [$rx1, $tx1] = $this->netTotals();
        $this->tick();
        [$rx2, $tx2] = $this->netTotals();

        $seconds = max(1, (int) config('server.metrics.sample_interval', 1));

        return [(int) max(0, intdiv($rx2 - $rx1, $seconds)), (int) max(0, intdiv($tx2 - $tx1, $seconds))];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function netTotals(): array
    {
        $rx = 0;
        $tx = 0;
        foreach (preg_split('/\r?\n/', $this->proc('net/dev')) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$iface, $data] = explode(':', $line, 2);
            if (in_array(trim($iface), ['lo', ''], true)) {
                continue;
            }
            $fields = preg_split('/\s+/', trim($data));
            $rx += (int) ($fields[0] ?? 0);
            $tx += (int) ($fields[8] ?? 0);
        }

        return [$rx, $tx];
    }

    // ---- facts helpers ----

    private function osName(): string
    {
        $path = (string) config('server.os_release', '/etc/os-release');
        if (is_file($path) && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', (string) @file_get_contents($path), $m)) {
            return trim($m[1]);
        }

        return trim($this->cmd(['uname', '-s']));
    }

    /**
     * @return array{seconds: int, human: string}
     */
    private function uptime(): array
    {
        $seconds = (int) (float) strtok(trim($this->proc('uptime')), ' ');

        return ['seconds' => $seconds, 'human' => now()->subSeconds($seconds)->diffForHumans(null, true)];
    }

    private function cpuModel(): string
    {
        if (preg_match('/^model name\s*:\s*(.+)$/m', $this->proc('cpuinfo'), $m)) {
            return trim($m[1]);
        }

        return 'Unknown';
    }

    private function cpuCores(): int
    {
        return max(1, substr_count($this->proc('cpuinfo'), 'processor'));
    }

    private function primaryIp(): string
    {
        return (string) strtok(trim($this->cmd(['hostname', '-I'])), ' ');
    }

    /**
     * Best-effort installed runtime versions (null when not present).
     *
     * @return array<string, string|null>
     */
    private function runtimes(): array
    {
        return [
            'php' => $this->version(['php', '-r', 'echo PHP_VERSION;']),
            'node' => $this->version(['node', '-v']),
            'nginx' => $this->version(['nginx', '-v']),
            'redis' => $this->version(['redis-server', '--version']),
            'mysql' => $this->version(['mysql', '--version']),
        ];
    }

    /**
     * @param  array<int, string>  $command
     */
    private function version(array $command): ?string
    {
        $result = $this->serverOps->run($command, ['feature' => 'dashboard', 'op' => 'runtime']);
        if ($result->failed()) {
            return null;
        }

        $out = trim($result->output()."\n".($result->result?->errorOutput() ?? ''));
        if ($out === '') {
            return null;
        }

        return preg_match('/\d+\.\d+(\.\d+)?/', $out, $m) ? $m[0] : $out;
    }

    // ---- low-level ----

    /**
     * @return array<string, int>
     */
    private function meminfo(): array
    {
        $info = [];
        foreach (preg_split('/\r?\n/', $this->proc('meminfo')) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $info[$m[1]] = (int) $m[2]; // kB
            }
        }

        return $info;
    }

    private function proc(string $file): string
    {
        $path = rtrim((string) config('server.proc_dir', '/proc'), '/').'/'.$file;

        return is_file($path) ? (string) @file_get_contents($path) : '';
    }

    /**
     * @param  array<int, string>  $command
     */
    private function cmd(array $command): string
    {
        return trim($this->serverOps->run($command, ['feature' => 'dashboard', 'op' => 'fact'])->output());
    }

    private function tick(): void
    {
        $interval = (int) config('server.metrics.sample_interval', 1);
        if ($interval > 0) {
            sleep($interval);
        }
    }
}
