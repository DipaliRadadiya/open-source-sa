<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Cronjob\CreateCronjob;
use App\Actions\Server\Cronjob\DeleteCronjob;
use App\Actions\Server\Cronjob\RunCronjobNow;
use App\Actions\Server\Cronjob\SwitchCronjobsPhp;
use App\Actions\Server\Cronjob\UpdateCronjob;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Cronjob\StoreCronjobRequest;
use App\Http\Requests\Server\Cronjob\SwitchCronjobsPhpRequest;
use App\Http\Requests\Server\Cronjob\UpdateCronjobRequest;
use App\Http\Resources\CronjobResource;
use App\Models\Application;
use App\Models\Cronjob;
use App\Services\Server\CronjobHistory;
use App\Services\Server\CrontabManager;
use App\Support\CronCommandPresets;
use App\Support\CronSchedulePresets;
use App\Support\ServerTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CronjobController extends Controller
{
    public function index(Request $request, CrontabManager $crontab, CronjobHistory $history): JsonResponse
    {
        // `id` breaks ties: created_at has one-second resolution, and jobs
        // made in the same second (a Sync adopting many at once) otherwise
        // come back in any order — one could appear on two pages or on none.
        $query = Cronjob::query()->with('systemUser:id,username', 'application:id,php_version')->latest()->orderByDesc('id');

        if ($systemUserId = $request->input('filter.system_user_id')) {
            $query->where('system_user_id', $systemUserId);
        }

        if ($applicationId = $request->input('filter.application_id')) {
            $query->where('application_id', $applicationId);
        }

        if ($username = $request->input('filter.username')) {
            $query->where('username', $username);
        }

        if ($request->has('filter.active')) {
            $query->where('active', $request->boolean('filter.active'));
        }

        $perPage = (int) $request->input('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 10;
        $paginator = $query->paginate($perPage);

        // One read for the page, not one per job.
        $latest = $history->latest($paginator->items());

        foreach ($paginator->items() as $cronjob) {
            $cronjob->withLastRun($latest[$cronjob->id] ?? null);
        }

        return response()->json([
            'cronjobs' => CronjobResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                // Every account a job runs as, across all pages, so the
                // "Runs as" filter can offer accounts whose jobs are on
                // another page (root, www-data, …), not only System Users.
                'usernames' => Cronjob::query()->distinct()->orderBy('username')->pluck('username')->filter()->values(),
                // False when the cron daemon is stopped — then no job runs,
                // whatever the list says. Null when it could not be told.
                'cron_running' => $crontab->serviceRunning(),
            ],
        ]);
    }

    /**
     * Schedule presets for the frontend dropdown (single source of truth).
     */
    public function schedulePresets(): JsonResponse
    {
        return response()->json([
            'presets' => CronSchedulePresets::all(),
        ]);
    }

    /**
     * Framework command presets — click to fill the command (+ schedule).
     * `{path}` is a placeholder the frontend swaps for the app directory.
     */
    public function commandPresets(): JsonResponse
    {
        return response()->json([
            'presets' => CronCommandPresets::all(),
            // The token in preset commands the frontend must substitute with the
            // target application's absolute directory before submitting.
            'placeholder' => CronCommandPresets::PLACEHOLDER,
            // …and the one to substitute with that application's `php_binary`
            // (plain `php` when no application is chosen).
            'php_placeholder' => CronCommandPresets::PHP_PLACEHOLDER,
        ]);
    }

    public function store(StoreCronjobRequest $request, CreateCronjob $action): JsonResponse
    {
        $cronjob = $action->execute($request->validated());

        return response()->json([
            'cronjob' => CronjobResource::make($cronjob->load('systemUser'))->resolve(),
        ], 201);
    }

    public function show(Cronjob $cronjob, CronjobHistory $history): JsonResponse
    {
        $cronjob->withLastRun($history->latest([$cronjob])[$cronjob->id] ?? null);

        return response()->json([
            'cronjob' => CronjobResource::make($cronjob->load('systemUser'))->resolve(),
        ]);
    }

    /**
     * Point the cron jobs the user ticked at another PHP version (#5). Only
     * the jobs named, only their interpreter; the request has already checked
     * each is this site's and has a PHP the panel can swap.
     */
    public function switchPhp(SwitchCronjobsPhpRequest $request, Application $application, SwitchCronjobsPhp $action): JsonResponse
    {
        $ids = array_map('intval', $request->validated('cronjob_ids'));
        $jobs = Cronjob::query()->whereIn('id', $ids)->orderBy('name')->get();

        $result = $action->execute($jobs, (string) $request->validated('version'));

        return response()->json([
            'switched' => CronjobResource::collection(collect($result['switched'])->each->load('systemUser', 'application'))->resolve(),
            'unchanged' => collect($result['unchanged'])->pluck('id')->values(),
        ]);
    }

    /**
     * The job's latest runs, newest first, each with what it printed.
     */
    public function runs(Cronjob $cronjob, CronjobHistory $history): JsonResponse
    {
        return response()->json([
            'runs' => $history->runs($cronjob),
            'timezone' => ServerTimezone::get(),
        ]);
    }

    /**
     * Run the job once now, in the background. 202: it has started, not
     * finished — its result appears in `runs`.
     */
    public function run(Cronjob $cronjob, RunCronjobNow $action): JsonResponse
    {
        $action->execute($cronjob);

        return response()->json([
            'message' => __('cronjob.run_started'),
        ], 202);
    }

    public function update(UpdateCronjobRequest $request, Cronjob $cronjob, UpdateCronjob $action): JsonResponse
    {
        $cronjob = $action->execute($cronjob, $request->validated());

        return response()->json([
            'cronjob' => CronjobResource::make($cronjob->load('systemUser'))->resolve(),
        ]);
    }

    public function destroy(Cronjob $cronjob, DeleteCronjob $action): JsonResponse
    {
        $action->execute($cronjob);

        return response()->json(null, 204);
    }
}
