<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;

/**
 * Resource usage for a systemd unit, built from properties the service list
 * already fetches, so it costs no extra process.
 *
 * `CPUUsageNSec` is CPU used since the unit started, so dividing it by uptime gives a lifetime
 * average, not current load. A real percentage needs two samples: instead of sleeping in the
 * request, each read stores its sample and the next one measures against it ("CPU used since
 * you last looked"). The first read has nothing to compare against and reports null.
 */
class ServiceUsage
{
    /**
     * Usage for one unit, or null when it can't be measured (stopped, or accounting off).
     * A missing measurement is never rendered as zero.
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
     * Fill in the memory and task figures systemd declined to report, from the kernel cgroup
     * counters it would have read them from.
     *
     * OpenLiteSpeed's `lshttpd.service` disables Memory/TasksAccounting, so `systemctl show`
     * answers `[not set]`. MemoryCurrent is the cgroup's `memory.current`, so reading it is
     * equivalent. Read-only: the vendor's unit is not ours to edit.
     *
     * A figure systemd does report always wins. A missing, unreadable or non-numeric counter
     * stays missing, never zero.
     *
     * @param  array<string, string|null>  $properties  raw `systemctl show` values, incl. ControlGroup
     * @return array<string, string|null>
     */
    public function withCgroupFallback(array $properties): array
    {
        $files = ['MemoryCurrent' => 'memory.current', 'TasksCurrent' => 'pids.current'];

        foreach ($files as $property => $file) {
            if ($this->number($properties[$property] ?? null) !== null) {
                continue;
            }

            $dir = $this->cgroupDir($properties['ControlGroup'] ?? null);

            if ($dir === null || ! is_readable("{$dir}/{$file}")) {
                continue;
            }

            // Not validated here: build() reads it through number().
            $properties[$property] = trim((string) file_get_contents("{$dir}/{$file}"));
        }

        return $properties;
    }

    /**
     * The unit's directory under the cgroup mount, or null when systemd gave no usable path
     * (empty for a stopped unit). Anything that could step outside the mount is refused.
     */
    private function cgroupDir(?string $controlGroup): ?string
    {
        $controlGroup = trim((string) $controlGroup);

        if (preg_match('#^(/[A-Za-z0-9@:_.\\\\-]+)+$#', $controlGroup) !== 1
            || preg_match('#(^|/)\.\.?(/|$)#', $controlGroup) === 1) {
            return null;
        }

        return rtrim((string) config('server.cgroup_dir', '/sys/fs/cgroup'), '/').$controlGroup;
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

        // Too close together to measure, or too far apart to mean anything. A negative delta
        // means the unit restarted (counter reset).
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
