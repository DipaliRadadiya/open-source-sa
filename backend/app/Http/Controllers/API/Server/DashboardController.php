<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerMetricResource;
use App\Models\ServerMetric;
use App\Services\Server\Metrics\ServerMetrics;
use App\Support\Bytes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

class DashboardController extends Controller
{
    /**
     * Server info card — read once, changes rarely.
     */
    public function facts(ServerMetrics $metrics): JsonResponse
    {
        return response()->json(['facts' => $metrics->facts()]);
    }

    /**
     * Live metric snapshot — poll for live gauges + the network stream chart.
     */
    public function live(ServerMetrics $metrics): JsonResponse
    {
        $snapshot = $metrics->snapshot();

        return response()->json(['metrics' => [
            ...$snapshot,
            'net_in_human' => Bytes::human((int) $snapshot['net_in']).'/s',
            'net_out_human' => Bytes::human((int) $snapshot['net_out']).'/s',
        ]]);
    }

    /**
     * Top server processes (server process table).
     */
    public function processes(ServerMetrics $metrics): JsonResponse
    {
        return response()->json(['processes' => $metrics->processes()]);
    }

    /**
     * 24h metric history for the CPU / Memory / Disk / Load charts.
     */
    public function history(): JsonResponse
    {
        $cutoff = Date::now()->subHours((int) config('server.metrics.retention_hours', 24));

        $rows = ServerMetric::query()
            ->where('sampled_at', '>=', $cutoff)
            ->orderBy('sampled_at')
            ->get();

        return response()->json([
            'metrics' => ServerMetricResource::collection($rows),
        ]);
    }
}
