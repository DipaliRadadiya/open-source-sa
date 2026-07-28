<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Models\DbMetric;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class DatabaseMonitorController extends Controller
{
    /**
     * Live DB processes / operations for an engine (DB process table).
     */
    public function processes(Request $request, DatabaseManager $manager): JsonResponse
    {
        $engine = $this->engine($request, $manager);

        return response()->json(['processes' => $manager->engine($engine)->processes()]);
    }

    /**
     * Guarded kill of a DB process/operation (manage).
     */
    public function killProcess(Request $request, string $id, DatabaseManager $manager, ActivityLogger $log): JsonResponse
    {
        $engine = $this->engine($request, $manager);
        $manager->engine($engine)->killProcess($id);

        $log->log('database.process_killed', null, ['engine' => $engine, 'process' => $id]);

        return response()->json(null, 204);
    }

    /**
     * Health snapshot for an engine (connections / uptime / queries / slow).
     */
    public function status(string $engine, DatabaseManager $manager): JsonResponse
    {
        abort_unless(in_array($engine, $manager->engineNames(), true), 404);

        return response()->json(['status' => $manager->engine($engine)->status()]);
    }

    /**
     * 24h Query Monitor history — QPS derived from consecutive cumulative
     * counters (like the server network rate).
     */
    public function history(Request $request, DatabaseManager $manager): JsonResponse
    {
        $engine = $this->engine($request, $manager);
        $cutoff = Date::now()->subHours((int) config('server.metrics.retention_hours', 24));

        $rows = DbMetric::query()
            ->where('engine', $engine)
            ->where('sampled_at', '>=', $cutoff)
            ->orderBy('sampled_at')
            ->get();

        $previous = null;
        $metrics = $rows->map(function ($row) use (&$previous) {
            $qps = 0.0;
            if ($previous) {
                $seconds = max(1, $previous->sampled_at->diffInSeconds($row->sampled_at));
                $qps = round(max(0, $row->queries - $previous->queries) / $seconds, 2);
            }
            $previous = $row;

            return [
                'sampled_at' => $row->sampled_at->format('d-m-Y H:i:s'),
                'qps' => $qps,
                'connections' => $row->connections,
                'threads_running' => $row->threads_running,
            ];
        })->values();

        return response()->json(['metrics' => $metrics]);
    }

    private function engine(Request $request, DatabaseManager $manager): string
    {
        $engine = (string) $request->query('engine');
        abort_unless(in_array($engine, $manager->engineNames(), true), 404);

        return $engine;
    }
}
