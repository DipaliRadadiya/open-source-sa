<?php

namespace App\Console\Commands;

use App\Models\ServerMetric;
use App\Services\Server\Metrics\ServerMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Samples the server metrics every 5 minutes (Laravel scheduler) into
 * `server_metrics` for the 24h charts, then prunes anything older than the
 * retention window — so the table stays bounded (~288 rows) and never grows.
 */
class SampleServerMetrics extends Command
{
    protected $signature = 'server:sample-metrics';

    protected $description = 'Sample server metrics for the 24h dashboard charts and prune old rows.';

    public function handle(ServerMetrics $metrics): int
    {
        ServerMetric::create([...$metrics->snapshot(), 'sampled_at' => Date::now()]);

        $cutoff = Date::now()->subHours((int) config('server.metrics.retention_hours', 24));
        ServerMetric::query()->where('sampled_at', '<', $cutoff)->delete();

        return self::SUCCESS;
    }
}
