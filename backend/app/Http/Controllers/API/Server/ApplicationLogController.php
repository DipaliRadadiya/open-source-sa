<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\ShowApplicationLogRequest;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationLogManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A site's own logs — access, error, and for a supervised application the process output.
 *
 * The client only names a source by key; the path is resolved server-side from the
 * web-server driver, so no request can point this at a file of its choosing. That is
 * what makes the `DELETE` safe to offer.
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

        $from = $request->validated('from');

        // A line number means a line in a file. The journal and Docker are
        // streams with no stable numbering to start from.
        if ($from !== null && $source['kind'] !== 'file') {
            throw ValidationException::withMessages(['from' => [__('app_log.errors.range_unsupported')]]);
        }

        // IP, status, method and URL exist only on an access-log line.
        if ($request->hasFields() && ! $logs->supportsFieldFilters($application, $key)) {
            throw ValidationException::withMessages(['log' => [__('app_log.errors.fields_unsupported')]]);
        }

        $content = $logs->read(
            $application,
            $key,
            (int) ($request->validated('lines') ?? ApplicationLogManager::DEFAULT_LINES),
            $request->validated('grep'),
            $from === null ? null : (int) $from,
            $request->fields(),
        );

        if ($content === null) {
            // Valid source with nothing to read yet (e.g. a never-visited site has no access
            // log): answer with an empty log, not a 500.
            return response()->json([
                'log' => [
                    'key' => $key,
                    'label' => __('app_log.sources.'.$key),
                    'kind' => $source['kind'],
                    'exists' => false,
                    'lines' => [],
                    'first_line' => null,
                    'truncated' => false,
                    // Same shape as the non-empty response.
                    'search_window_capped' => false,
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

    /**
     * Download one log whole; the screen shows the last lines only.
     */
    public function download(
        Application $application,
        string $key,
        ApplicationLogManager $logs,
        ActivityLogger $activity,
    ): StreamedResponse|JsonResponse {
        $file = $logs->download($application, $key);

        if ($file === false) {
            return response()->json([
                'message' => __('app_log.errors.not_downloadable'),
                'reason' => 'not_downloadable',
            ], 422);
        }

        abort_if($file === null, 404, __('app_log.errors.unknown_source'));

        $activity->log('application.log_downloaded', $application, [
            'name' => $application->name,
            'log' => __('app_log.sources.'.$key),
        ]);

        return response()->stream(function () use ($file): void {
            foreach ($file['chunks'] as $chunk) {
                echo $chunk;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Empty one log. `app_log` **manage**.
     *
     * Truncated rather than deleted — see ApplicationLogManager::clear(). Always logged to
     * the activity feed with the source named.
     */
    public function destroy(
        Application $application,
        string $key,
        ApplicationLogManager $logs,
        ActivityLogger $activity,
    ): JsonResponse {
        $source = $logs->find($application, $key);

        if ($source === null) {
            abort(404, __('app_log.errors.unknown_source'));
        }

        // On OpenLiteSpeed the firewall detections are lines in the access
        // log, so "clear" here would empty the access log.
        if (isset($source['marker'])) {
            abort(422, __('app_log.errors.clear_shared'));
        }

        $logs->clear($application, $key);

        $activity->log('application.log_cleared', $application, ['log' => $key, 'name' => $application->name]);

        // The source still exists, now empty, so the client can re-read it.
        return response()->json([
            'log' => [
                'key' => $key,
                'label' => __('app_log.sources.'.$key),
                'lines' => [],
                'truncated' => false,
            ],
        ]);
    }
}
