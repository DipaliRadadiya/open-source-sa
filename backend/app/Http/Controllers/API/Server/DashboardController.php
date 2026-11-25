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
use App\Support\ProbeCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

class DashboardController extends Controller
{
    /**
     * Server info card — read once, changes rarely.
     */
    public function facts(ServerMetrics $metrics): JsonResponse
    {
        // Hostname, OS, CPU, runtimes… are a dozen commands (0.5–0.8 s), so cached.
        // Uptime and the reboot flag are read fresh.
        $facts = ProbeCache::remember('server-facts', fn (): array => $metrics->facts());

        return response()->json(['facts' => array_merge($facts, $metrics->volatileFacts())]);
    }

    /**
     * Live metrics: each resource as total/used/free/percent (+ human), plus the network rate.
     */
    public function live(ServerMetrics $metrics): JsonResponse
    {
        return response()->json(['metrics' => $metrics->live()]);
    }

    /**
     * Top server processes (server process table).
     *
     * `meta.total` is how many processes the box runs; the list is the top `meta.limit` by CPU.
     */
    public function processes(ServerMetrics $metrics): JsonResponse
    {
        $result = $metrics->processes();

        return response()->json([
            'processes' => $result['processes'],
            'meta' => [
                'total' => $result['total'],
                'limit' => $result['limit'],
            ],
        ]);
    }

    /**
     * Stop a process (manage).
     *
     * The guards live in ProcessKiller. Logged with what was actually killed, read at kill time,
     * not taken from the request.
     */
    public function killProcess(KillProcessRequest $request, string $pid, ProcessKiller $killer, ActivityLogger $log): JsonResponse
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
     * Per-application dashboard issues: all health signals in one call. Read-only; each issue type
     * is independently null-safe (a site with no workers returns no worker issue, not an error).
     */
    public function issues(Application $application, AppIssueDetector $detector): JsonResponse
    {
        $application->loadMissing(['certificate', 'domains']);

        $issues = $detector->issues($application);

        // `healthy` means zero critical and zero warning; info-level issues do not count.
        $hasCritical = $issues->contains(fn (array $issue) => $issue['severity'] === 'critical');
        $hasWarning = $issues->contains(fn (array $issue) => $issue['severity'] === 'warning');

        return response()->json([
            'issues' => AppIssueResource::collection($issues->values())->resolve(),
            'healthy' => ! $hasCritical && ! $hasWarning,
        ]);
    }
}
