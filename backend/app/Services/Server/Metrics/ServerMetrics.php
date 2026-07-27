<?php

namespace App\Services\Server\Metrics;

use App\Services\Server\ServerOps;
use App\Support\Bytes;

/**
 * Reads server facts + live metrics cheaply from `/proc` (+ df / small probes).
 * Everything is world-readable, so this works without root (detect-don't-trust).
 * CPU % and network rate need two reads a short interval apart (cumulative
 * counters). The same `snapshot()` powers the live endpoint and the 5-min
 * collector.
 */
class ServerMetrics
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Live metric snapshot (the 9 stored fields).
     *
     * @return array<string, int|float>
     */
    public function snapshot(): array
    {
        [$load1, $load5, $load15] = $this->load();
        [$memoryPercent, $swapPercent] = $this->memory();
        [$netIn, $netOut] = $this->network();

        return [
            'cpu_percent' => $this->cpuPercent(),
            'memory_percent' => $memoryPercent,
            'swap_percent' => $swapPercent,
            'disk_percent' => $this->diskPercent(),
            'load_1' => $load1,
            'load_5' => $load5,
            'load_15' => $load15,
            'net_in' => $netIn,
            'net_out' => $netOut,
        ];
    }

    /**
     * Static-ish server facts for the dashboard info card.
     *
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        $meminfo = $this->meminfo();
        $ramTotal = ($meminfo['MemTotal'] ?? 0) * 1024;

        return [
            'hostname' => $this->cmd(['hostname', '--fqdn']) ?: (string) gethostname(),
            'os' => $this->osName(),
            'kernel' => $this->cmd(['uname', '-r']),
            'arch' => $this->cmd(['uname', '-m']),
            'uptime' => $this->uptime(),
            'ip' => $this->primaryIp(),
            'cpu' => ['model' => $this->cpuModel(), 'cores' => $this->cpuCores()],
            'memory_total' => $ramTotal,
            'memory_total_human' => Bytes::human((int) $ramTotal),
            'disk_total' => $this->diskTotal(),
            'disk_total_human' => Bytes::human($this->diskTotal()),
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

    // ---- metric helpers ----

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function load(): array
    {
        $parts = preg_split('/\s+/', trim($this->proc('loadavg')));

        return [(float) ($parts[0] ?? 0), (float) ($parts[1] ?? 0), (float) ($parts[2] ?? 0)];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function memory(): array
    {
        $m = $this->meminfo();
        $memTotal = $m['MemTotal'] ?? 0;
        $memAvail = $m['MemAvailable'] ?? 0;
        $swapTotal = $m['SwapTotal'] ?? 0;
        $swapFree = $m['SwapFree'] ?? 0;

        return [
            $memTotal > 0 ? round(($memTotal - $memAvail) / $memTotal * 100, 2) : 0.0,
            $swapTotal > 0 ? round(($swapTotal - $swapFree) / $swapTotal * 100, 2) : 0.0,
        ];
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

    private function diskPercent(): float
    {
        return (float) rtrim($this->dfField(4), '%');
    }

    private function diskTotal(): int
    {
        return (int) $this->dfField(1); // -B1 → bytes
    }

    private function dfField(int $index): string
    {
        $output = $this->serverOps->run(
            ['df', '-B1', '-P', (string) config('server.disk_path', '/')],
            ['feature' => 'dashboard', 'op' => 'disk'],
        )->output();

        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($output)) ?: []));
        $row = preg_split('/\s+/', trim((string) end($lines))) ?: [];

        return (string) ($row[$index] ?? '0');
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
