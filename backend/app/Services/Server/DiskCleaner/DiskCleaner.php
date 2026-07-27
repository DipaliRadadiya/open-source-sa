<?php

namespace App\Services\Server\DiskCleaner;

use App\Contracts\CleanupTarget;
use App\Services\Server\ServerOps;
use App\Support\Bytes;

/**
 * Disk-cleaner orchestrator. Resolves the configured cleanup targets, builds
 * the preview (disk usage + per-category estimate/paths/method), and looks up
 * a target by key. No DB — everything is detected live (detect-don't-trust),
 * the same posture as the Services feature.
 */
class DiskCleaner
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * All registered targets (resolved from the container).
     *
     * @return array<int, CleanupTarget>
     */
    public function targets(): array
    {
        return array_map(
            fn (string $class) => app($class),
            config('server.disk_cleaner.targets', []),
        );
    }

    public function find(string $key): ?CleanupTarget
    {
        foreach ($this->targets() as $target) {
            if ($target->key() === $key) {
                return $target;
            }
        }

        return null;
    }

    /**
     * Preview: disk usage + the available categories with reclaimable
     * estimates and the exact paths each will touch.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        return [
            'disk' => $this->disk(),
            'categories' => array_values(array_filter(array_map(
                fn (CleanupTarget $target) => $target->available() ? $this->describe($target) : null,
                $this->targets(),
            ))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(CleanupTarget $target): array
    {
        $reclaimable = $target->estimate();

        return [
            'key' => $target->key(),
            'label' => __("disk-cleaner.{$target->key()}.label"),
            'description' => __("disk-cleaner.{$target->key()}.description"),
            'group' => $target->group(),
            'method' => $target->method(),
            'paths' => $target->paths(),
            'safe' => $target->safe(),
            'available' => true,
            'reclaimable' => $reclaimable,
            'reclaimable_human' => Bytes::human($reclaimable),
        ];
    }

    /**
     * Live disk usage for the configured filesystem, via `df`.
     *
     * @return array<string, mixed>
     */
    public function disk(): array
    {
        $path = (string) config('server.disk_path', '/');

        $output = $this->serverOps->run(
            ['df', '-B1', '-P', $path],
            ['feature' => 'disk_cleaner', 'op' => 'df'],
        )->output();

        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($output)) ?: []));
        $row = preg_split('/\s+/', trim((string) end($lines))) ?: [];

        $total = (int) ($row[1] ?? 0);
        $used = (int) ($row[2] ?? 0);
        $free = (int) ($row[3] ?? 0);

        return [
            'path' => $path,
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'total_human' => Bytes::human($total),
            'used_human' => Bytes::human($used),
            'free_human' => Bytes::human($free),
            'percent' => $total > 0 ? (int) round($used / $total * 100) : 0,
        ];
    }
}
