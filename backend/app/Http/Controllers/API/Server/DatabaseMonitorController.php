<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\DatabaseEngineRequest;
use App\Models\DbMetric;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

class DatabaseMonitorController extends Controller
{
    /**
     * Live DB processes / operations for an engine (DB process table).
     */
    public function processes(DatabaseEngineRequest $request, DatabaseManager $manager): JsonResponse
    {
        $engine = $request->engine();

        $own = $this->panelUsername($manager, $engine);

        // `is_panel` (FS-C14b): the panel's own connection, which must not get
        // a Stop button. Said here so the screen does not have to work it out.
        $processes = array_map(
            fn (array $process): array => $process + ['is_panel' => $this->isPanel($process, $own)],
            $manager->engine($engine)->processes(),
        );

        return response()->json(['processes' => $processes]);
    }

    /**
     * Guarded kill of a DB process/operation (manage).
     */
    public function killProcess(DatabaseEngineRequest $request, string $id, DatabaseManager $manager, ActivityLogger $log): JsonResponse
    {
        $engine = $request->engine();
        $own = $this->panelUsername($manager, $engine);

        // FS-C14(b): refused, not only hidden. Killing the panel's own
        // connection fails whatever it was doing for somebody.
        $target = collect($manager->engine($engine)->processes())->first(fn (array $p): bool => (string) $p['id'] === $id);

        if ($target !== null && $this->isPanel($target, $own)) {
            return response()->json([
                'message' => __('errors/database.panel_process_protected'),
                'reason' => 'panel_process',
            ], 422);
        }

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
    public function history(DatabaseEngineRequest $request): JsonResponse
    {
        $engine = $request->engine();
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

    private function panelUsername(DatabaseManager $manager, string $engine): ?string
    {
        $username = $manager->connection($engine)->username;

        // `root` is the default before the panel has its own account: every
        // root session on the box would then read as the panel's.
        return $username === null || $username === '' || $username === 'root' ? null : (string) $username;
    }

    /**
     * @param  array<string, mixed>  $process
     */
    private function isPanel(array $process, ?string $own): bool
    {
        // Some engines report `user@host`.
        return $own !== null && explode('@', (string) ($process['user'] ?? ''))[0] === $own;
    }
}
