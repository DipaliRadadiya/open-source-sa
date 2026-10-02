<?php

namespace App\Http\Controllers\API\Server\Addons;

use App\Enums\ApplicationStatus;
use App\Exceptions\Addons\AddonException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Addons\InsightsReportRequest;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Addons\InsightHubToolkit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * InsightHub for Central: register a site, read its reports.
 *
 * Reports are cached briefly: every call starts the binary and opens its
 * database, and a dashboard asks for several at once.
 */
class InsightsAddonController extends Controller
{
    /**
     * Group and report in the route => the toolkit command. The paths are
     * insighthub-agent's own, so what Central asked v7 for maps one to one.
     */
    public const REPORTS = [
        'dashboard' => [
            'stats' => 'dashboard:stats',
            'top-records' => 'dashboard:top-records',
            'daily-log-count' => 'dashboard:daily-log-count',
            'top-url-count' => 'dashboard:top-url-count',
            'method-count' => 'dashboard:method-count',
            'bot-count' => 'dashboard:bot-count',
            'device-type-count' => 'dashboard:device-type-count',
            'url-method-status-count' => 'dashboard:url-method-status-count',
            'status-count' => 'dashboard:status-count',
            'country-count' => 'dashboard:country-count',
            'mimetype-bandwidth-sum' => 'dashboard:mimetype-bandwidth-sum',
            'url-mimetype-bandwidth-sum' => 'dashboard:url-mimetype-bandwidth-sum',
            'bot-vs-human' => 'dashboard:bot-vs-human',
        ],
        'traffic' => [
            'summary' => 'traffic:summary',
            'daily-request-count' => 'traffic:daily-request-count',
            'top-ips-by-url' => 'traffic:top-ips-by-url',
            'top-ips-by-country' => 'traffic:top-ips-by-country',
            'latest-access-logs' => 'traffic:latest-access-logs',
            'referer-count' => 'traffic:referer-count',
            'url-and-field-count' => 'traffic:url-and-field-count',
            'sitemap-url' => 'traffic:sitemap-url',
            'url-and-method-count' => 'traffic:url-and-method-count',
            'url-and-status-count' => 'traffic:url-and-status-count',
        ],
        'errors' => [
            'stats' => 'errors:stats',
            'error-rate' => 'errors:error-rate',
            'error-code-breakdown' => 'errors:error-code-breakdown',
            'status-code-data' => 'errors:status-code-data',
            'bot-vs-human' => 'errors:bot-vs-human',
            'status-summary' => 'errors:status-summary',
            'status-code-trends' => 'errors:status-code-trends',
            'referer-error-logs' => 'errors:referer-error-logs',
        ],
        'bots' => [
            'summary' => 'bots:summary',
            'bot-traffic-trends' => 'bots:traffic-trends',
            'url-status-count' => 'bots:url-status-count',
            'traffic-distribution' => 'bots:traffic-distribution',
            'most-crawled-urls' => 'bots:most-crawled-urls',
            'traffic-by-ip' => 'bots:traffic-by-ip',
            'traffic-percentage' => 'bots:traffic-percentage',
        ],
        'user-agents' => [
            'summary' => 'user-agents:summary',
            'breakdown' => 'user-agents:breakdown',
            'popular' => 'user-agents:popular',
            'url-request-count' => 'user-agents:url-request-count',
            'os-pie-chart' => 'user-agents:os-pie-chart',
        ],
        'bandwidth' => [
            'summary' => 'bandwidth:summary',
            'trends' => 'bandwidth:trends',
            'bot-vs-human' => 'bandwidth:bot-vs-human',
            'high-usage-urls' => 'bandwidth:high-usage-urls',
            'by-file-type' => 'bandwidth:by-file-type',
            'top-ips' => 'bandwidth:top-ips',
        ],
    ];

    /**
     * Reports that are one object or one series rather than a list, and so
     * take no --limit. The toolkit refuses a flag a command does not define,
     * so a `?limit=` sent to one of these is ignored here rather than passed
     * on (found live: every one of these answered 502).
     */
    private const UNLIMITED = [
        'dashboard:stats', 'dashboard:top-records', 'dashboard:daily-log-count', 'dashboard:bot-vs-human',
        'traffic:summary', 'traffic:daily-request-count',
        'errors:stats', 'errors:error-rate', 'errors:bot-vs-human', 'errors:status-summary', 'errors:status-code-trends',
        'bots:summary', 'bots:traffic-trends', 'bots:traffic-percentage',
        'user-agents:summary',
        'bandwidth:summary', 'bandwidth:trends', 'bandwidth:bot-vs-human',
    ];

    /** Reports that need one more option, and which. */
    private const REQUIRED_OPTION = [
        'traffic:url-and-field-count' => 'field',
        'errors:status-code-data' => 'status_code',
    ];

    public function applications(InsightHubToolkit $toolkit): JsonResponse
    {
        return response()->json($toolkit->run(['applications:list'], (int) config('server.addons.sync_timeout')));
    }

    public function register(Application $application, InsightHubToolkit $toolkit, ActivityLogger $log): JsonResponse
    {
        abort_unless($application->status === ApplicationStatus::Active, 404);

        $answer = $toolkit->register($application);

        $log->log('application.addon_command', $application, ['domain' => $application->domain, 'addon' => $toolkit->label(), 'command' => 'register']);

        return response()->json($answer);
    }

    public function unregister(Application $application, InsightHubToolkit $toolkit, ActivityLogger $log): JsonResponse
    {
        $toolkit->unregister($application);

        $log->log('application.addon_command', $application, ['domain' => $application->domain, 'addon' => $toolkit->label(), 'command' => 'unregister']);

        return response()->json(['message' => __('errors/addons.unregistered')]);
    }

    public function report(InsightsReportRequest $request, Application $application, string $group, string $report, InsightHubToolkit $toolkit): JsonResponse
    {
        $command = self::REPORTS[$group][$report] ?? abort(404);

        if ($application->insighthub_id === null) {
            throw AddonException::of('addon_site_not_registered', $toolkit->label());
        }

        $arguments = [$command, '--application='.$application->insighthub_id];

        if ($request->filled('limit') && ! in_array($command, self::UNLIMITED, true)) {
            $arguments[] = '--limit='.(int) $request->validated('limit');
        }

        if ($option = self::REQUIRED_OPTION[$command] ?? null) {
            if (! $request->filled($option)) {
                throw ValidationException::withMessages([$option => [__('errors/addons.option_required', ['option' => $option])]]);
            }

            $arguments[] = '--'.str_replace('_', '-', $option).'='.$request->validated($option);
        }

        $key = 'addon:insights:'.$application->id.':'.implode(' ', $arguments);

        // Only answers are cached; an error is asked again next time.
        $answer = Cache::remember($key, (int) config('server.addons.insighthub.cache_seconds'), fn () => $toolkit->run($arguments, (int) config('server.addons.sync_timeout'), ['application' => $application->id]));

        return response()->json($answer);
    }
}
