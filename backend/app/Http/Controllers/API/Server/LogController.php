<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Log\DownloadLogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Log\ShowLogRequest;
use App\Services\Server\LogManager;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LogController extends Controller
{
    /**
     * Detected log sources with live metadata.
     */
    public function index(LogManager $logs): JsonResponse
    {
        return response()->json([
            'logs' => $logs->list(),
        ]);
    }

    /**
     * Read one source: last N lines, incremental bytes since a cursor, or a
     * literal filter. The client references the source by `key`; the path is
     * resolved server-side from the registry.
     */
    public function show(ShowLogRequest $request, string $key, LogManager $logs): JsonResponse
    {
        $source = $logs->find($key);

        // `describe()` answers existence per kind — a privileged source is
        // never `is_file` to this process, and the journal is not a file at
        // all, so checking that here would 404 both of them.
        if (! $source || $logs->describe($source) === null) {
            abort(404, __('errors/log.not_found'));
        }

        if (($source['kind'] ?? 'file') === 'file' && ! is_readable($source['path'])) {
            abort(403, __('errors/log.unreadable'));
        }

        $lines = (int) ($request->validated('lines') ?? LogManager::DEFAULT_LINES);
        $filter = $request->validated('grep');
        $after = $request->has('after') ? (int) $request->validated('after') : null;

        $content = $logs->read($key, $lines, $filter, $after);

        return response()->json([
            'log' => array_merge([
                'key' => $source['key'],
                'label' => $source['label'],
                'group' => $source['group'],
                'kind' => $source['kind'] ?? 'file',
            ], $content),
        ]);
    }

    /**
     * Stream a full log file as a download.
     */
    public function download(string $key, DownloadLogAction $action): BinaryFileResponse
    {
        $path = $action->execute($key);

        return response()->download($path, "{$key}.log");
    }
}
