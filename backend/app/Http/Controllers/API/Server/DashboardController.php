<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Process\KillProcessRequest;
use App\Http\Resources\ServerMetricResource;
use App\Models\ServerMetric;
use App\Services\ActivityLogger;
use App\Services\Server\Metrics\ServerMetrics;
use App\Services\Server\ProcessKiller;
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
     * Stop a process (manage).
     *
     * The guards live in ProcessKiller — see there for what is refused and
     * why. Logged to the activity trail with what was actually killed, read
     * at kill time rather than taken from the request.
     */
    public function killProcess(KillProcessRequest $request, int $pid, ProcessKiller $killer, ActivityLogger $log): JsonResponse
    {
        $killed = $killer->kill($pid, (string) ($request->validated('signal') ?? 'TERM'));

        $log->log('server.process_killed', null, $killed);

        return response()->json(['process' => $killed]);
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
