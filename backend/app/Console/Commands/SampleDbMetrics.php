<?php

namespace App\Console\Commands;

use App\Models\DbMetric;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Samples per-engine DB metrics every 5 minutes into `db_metrics` for the
 * Query Monitor chart, then prunes past the retention window (bounded like
 * server_metrics). Only engines that are reachable are sampled.
 */
class SampleDbMetrics extends Command
{
    protected $signature = 'db:sample-metrics';

    protected $description = 'Sample per-engine DB metrics for the Query Monitor chart and prune old rows.';

    public function handle(DatabaseManager $manager): int
    {
        $now = Date::now();

        foreach ($manager->engineNames() as $engineName) {
            $engine = $manager->engine($engineName);
            if (! $engine->available()) {
                continue;
            }

            $status = $engine->status();
            DbMetric::create([
                'engine' => $engineName,
                'queries' => (int) ($status['queries'] ?? 0),
                'connections' => (int) ($status['connections'] ?? 0),
                'threads_running' => (int) ($status['threads_running'] ?? 0),
                'sampled_at' => $now,
            ]);
        }

        $cutoff = $now->copy()->subHours((int) config('server.metrics.retention_hours', 24));
        DbMetric::query()->where('sampled_at', '<', $cutoff)->delete();

        return self::SUCCESS;
    }
}
