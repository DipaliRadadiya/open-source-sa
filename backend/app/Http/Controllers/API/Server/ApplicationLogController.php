<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\ShowApplicationLogRequest;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationLogManager;
use Illuminate\Http\JsonResponse;

/**
 * A site's own logs — access, error, and for a supervised application the
 * process output as well.
 *
 * Read-only, and the client only ever names a source by key: the path is
 * resolved server-side from the web-server driver, so no request can point
 * this at a file of its choosing.
 */
class ApplicationLogController extends Controller
{
    /** Which sources this application has, and which of them exist yet. */
    public function index(Application $application, ApplicationLogManager $logs): JsonResponse
    {
        return response()->json([
            'logs' => $logs->list($application),
        ]);
    }

    public function show(
        ShowApplicationLogRequest $request,
        Application $application,
        string $key,
        ApplicationLogManager $logs,
    ): JsonResponse {
        $source = $logs->find($application, $key);

        if ($source === null) {
            abort(404, __('app_log.errors.unknown_source'));
        }

        $content = $logs->read(
            $application,
            $key,
            (int) ($request->validated('lines') ?? ApplicationLogManager::DEFAULT_LINES),
            $request->validated('grep'),
        );

        if ($content === null) {
            // The source is one this application should have, but there is
            // nothing to read — a site that has never been visited has no
            // access log. An empty screen that says so beats a 500.
            return response()->json([
                'log' => [
                    'key' => $key,
                    'label' => __('app_log.sources.'.$key),
                    'kind' => $source['kind'],
                    'exists' => false,
                    'lines' => [],
                    'truncated' => false,
                ],
            ]);
        }

        return response()->json([
            'log' => array_merge([
                'key' => $key,
                'label' => __('app_log.sources.'.$key),
                'kind' => $source['kind'],
                'exists' => true,
            ], $content),
        ]);
    }
}
