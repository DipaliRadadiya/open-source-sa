<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerMetricResource;
use App\Models\ServerMetric;
use App\Services\Server\Metrics\ServerMetrics;
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
     * Live metrics — each resource as total/used/free/percent (+ human) for the
     * gauges, plus the network rate for the live stream chart. Poll this.
     */
    public function live(ServerMetrics $metrics): JsonResponse
    {
        return response()->json(['metrics' => $metrics->live()]);
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
