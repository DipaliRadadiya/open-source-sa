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

/**
 * InsightHub for Central: register a site, read its reports.
 *
 * Reports are cached briefly: every call starts the binary and opens its
 * database, and a dashboard asks for several at once.
 */
class InsightsAddonController extends Controller
{
    /**
     * Report name in the route => the toolkit command. Grows as the toolkit
     * ports more of insighthub-agent's routes.
     */
    public const REPORTS = [
        'bandwidth' => [
            'summary' => 'bandwidth:summary',
            'trends' => 'bandwidth:trends',
            'bot-vs-human' => 'bandwidth:bot-vs-human',
            'high-usage-urls' => 'bandwidth:high-usage-urls',
            'by-file-type' => 'bandwidth:by-file-type',
            'top-ips' => 'bandwidth:top-ips',
        ],
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

        if ($request->filled('limit')) {
            $arguments[] = '--limit='.(int) $request->validated('limit');
        }

        $key = 'addon:insights:'.$application->id.':'.implode(' ', $arguments);

        // Only answers are cached; an error is asked again next time.
        $answer = Cache::remember($key, (int) config('server.addons.insighthub.cache_seconds'), fn () => $toolkit->run($arguments, (int) config('server.addons.sync_timeout'), ['application' => $application->id]));

        return response()->json($answer);
    }
}
