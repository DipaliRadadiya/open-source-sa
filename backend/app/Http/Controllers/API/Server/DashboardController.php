<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Process\KillProcessRequest;
use App\Http\Resources\AppIssueResource;
use App\Http\Resources\ServerMetricResource;
use App\Models\Application;
use App\Models\ServerMetric;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\AppIssueDetector;
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

    /**
     * Per-application dashboard issues — all health signals in one call.
     *
     * Read-only. Each issue type is independently null-safe (a site with no
     * workers returns no worker issue, not an error).
     */
    public function issues(Application $application, AppIssueDetector $detector): JsonResponse
    {
        $application->loadMissing(['certificate', 'domains']);

        $issues = $detector->issues($application);

        // `healthy` means zero critical and zero warning. Info-level issues
        // do not affect the healthy flag.
        $hasCritical = $issues->contains(fn (array $issue) => $issue['severity'] === 'critical');
        $hasWarning = $issues->contains(fn (array $issue) => $issue['severity'] === 'warning');

        return response()->json([
            'issues' => AppIssueResource::collection($issues->values())->resolve(),
            'healthy' => ! $hasCritical && ! $hasWarning,
        ]);
    }
}
