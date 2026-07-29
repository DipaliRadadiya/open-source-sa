<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;

/**
 * Resource usage for a systemd unit, built from properties the service list
 * already fetches — so showing usage costs no extra process.
 *
 * Memory is a live number and needs nothing clever. CPU is not: systemd
 * reports `CPUUsageNSec`, the total CPU consumed since the unit started, which
 * on a long-running service is a number in the hundreds of thousands of
 * seconds. Dividing it by uptime gives a lifetime average, which is not what
 * anyone means by "how busy is nginx right now".
 *
 * A real percentage needs two samples. Sleeping inside the request to take
 * them would block a worker on every page load, so instead each read stores
 * its sample and the next one measures against it — the frontend is polling
 * anyway, and the answer becomes "CPU used since you last looked". The first
 * read after a restart has nothing to compare against and reports null rather
 * than a number that would be wrong.
 */
class ServiceUsage
{
    /**
     * Usage for one unit, or null when it can't be measured — the unit is
     * stopped, or systemd accounting is off for it. A missing measurement is
     * reported as missing; it is not rendered as zero.
     *
     * @param  array<string, string|null>  $properties  raw `systemctl show` values
     * @return array<string, mixed>|null
     */
    public function build(string $unit, array $properties): ?array
    {
        $memory = $this->number($properties['MemoryCurrent'] ?? null);
        $cpuNsec = $this->number($properties['CPUUsageNSec'] ?? null);

        if ($memory === null && $cpuNsec === null) {
            return null;
        }

        return [
            'memory_bytes' => $memory,
            'memory_human' => $memory === null ? null : $this->human($memory),
            'memory_percent' => $memory === null ? null : $this->percentOfTotal($memory),
            'cpu_percent' => $cpuNsec === null ? null : $this->cpuPercent($unit, $cpuNsec),
            'tasks' => $this->number($properties['TasksCurrent'] ?? null),
        ];
    }

    /**
     * CPU used since the previous read of this unit, as a percentage of one
     * core (so a service saturating two cores reads 200%).
     */
    private function cpuPercent(string $unit, int $cpuNsec): ?float
    {
        $key = "service-usage:{$unit}";
        $now = microtime(true);
        $previous = Cache::get($key);

        Cache::put($key, ['nsec' => $cpuNsec, 'at' => $now], (int) config('server.usage_sample_ttl', 300));

        if (! is_array($previous)) {
            return null;
        }

        $elapsed = $now - (float) $previous['at'];

        // Too close together to measure, or so far apart that the average
        // would describe a window nobody is looking at any more. A restart
        // resets the counter, so a negative delta means a new lifetime.
        $window = (int) config('server.usage_sample_window', 60);

        if ($elapsed < 0.5 || $elapsed > $window || $cpuNsec < (int) $previous['nsec']) {
            return null;
        }

        $used = ($cpuNsec - (int) $previous['nsec']) / 1_000_000_000;

        return round($used / $elapsed * 100, 1);
    }

    private function percentOfTotal(int $bytes): ?float
    {
        $total = $this->memTotal();

        return $total > 0 ? round($bytes / $total * 100, 1) : null;
    }

    private function memTotal(): int
    {
        $path = rtrim((string) config('server.proc_dir', '/proc'), '/').'/meminfo';

        if (! is_readable($path)) {
            return 0;
        }

        // MemTotal is reported in kB.
        return preg_match('/^MemTotal:\s+(\d+)/m', (string) file_get_contents($path), $matches) === 1
            ? (int) $matches[1] * 1024
            : 0;
    }

    /**
     * systemd reports `[not set]` when accounting is disabled and `infinity`
     * for an unset limit; neither is a measurement.
     */
    private function number(?string $value): ?int
    {
        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
