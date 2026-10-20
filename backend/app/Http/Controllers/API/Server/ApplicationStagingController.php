<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\CreateStaging;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\CreateStagingRequest;
use App\Http\Requests\Server\Application\PullStagingRequest;
use App\Http\Requests\Server\Application\PushStagingRequest;
use App\Http\Requests\Server\Application\RestoreStagingSafetyCopyRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\StagingRunResource;
use App\Jobs\RunStagingSync;
use App\Models\Application;
use App\Models\Restore;
use App\Models\StagingRun;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\StagingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ApplicationStagingController extends Controller
{
    public function show(Application $application): JsonResponse
    {
        $latest = StagingRun::where('application_id', $application->id)->latest('id')->first();

        return response()->json([
            'staging' => $application->staging
                ? ApplicationResource::make($application->staging->load('systemUser'))->resolve()
                : null,
            // RC-16: the last push or update-from-live, so the page shows a
            // run in progress — and how the last one ended — after a reload.
            'latest_run' => $latest ? StagingRunResource::make($latest)->resolve() : null,
        ]);
    }

    public function store(CreateStagingRequest $request, Application $application, CreateStaging $action): JsonResponse
    {
        $staging = $action->execute($application, $request->domain());

        return response()->json([
            'staging' => ApplicationResource::make($staging->load('systemUser'))->resolve(),
        ], 201);
    }

    /**
     * Push staging onto production — on the queue (RC-16). `202` with the run;
     * poll `GET …/staging/runs/{run}`. Refused here, not in the job, when
     * something is already pushing or restoring the site, so a double click
     * gets an answer rather than a second run that would fail.
     */
    public function push(PushStagingRequest $request, Application $application): JsonResponse
    {
        return $this->start($application, 'push', $request->mode(), $request->wantsBackup());
    }

    /** Update staging from live — on the queue, as a push is. */
    public function pull(PullStagingRequest $request, Application $application): JsonResponse
    {
        return $this->start($application, 'pull', $request->mode(), false);
    }

    public function run(Application $application, StagingRun $run): JsonResponse
    {
        abort_unless($run->application_id === $application->id, 404);

        return response()->json(['run' => StagingRunResource::make($run)->resolve()]);
    }

    private function start(Application $application, string $kind, string $mode, bool $backup): JsonResponse
    {
        $staging = $application->staging;

        if (StagingRun::activeFor($application)
            || StagingManager::locked($application->id)
            || ($staging !== null && StagingManager::locked($staging->id))) {
            throw ValidationException::withMessages(['mode' => [__('errors/application.staging_push_running')]]);
        }

        if (Restore::inProgressFor($application->id) || ($staging !== null && Restore::inProgressFor($staging->id))) {
            throw ValidationException::withMessages(['mode' => [__('backup.errors.restore_already_running')]]);
        }

        $run = StagingRun::create([
            'application_id' => $application->id,
            'staging_application_id' => $staging?->id,
            'kind' => $kind,
            'mode' => $mode,
            'backup' => $backup,
            'status' => StagingRun::QUEUED,
            'user_id' => Auth::id(),
        ]);

        RunStagingSync::dispatch($run->id, $application->id, Auth::id());

        return response()->json([
            'run' => StagingRunResource::make($run->fresh())->resolve(),
        ], 202);
    }

    /**
     * ST-B3: the copies of the live database each push saves before replacing
     * it, newest first. The newest three are kept.
     */
    public function safetyCopies(Application $application, StagingManager $staging): JsonResponse
    {
        return response()->json([
            'safety_copies' => $staging->safetyDumps($application),
            'kept' => StagingManager::KEEP_PRE_PUSH_DUMPS,
        ]);
    }

    public function restoreSafetyCopy(RestoreStagingSafetyCopyRequest $request, Application $application, string $name, StagingManager $staging, ActivityLogger $activity): JsonResponse
    {
        $staging->restoreSafetyDump($application, $name);

        $activity->log('application.staging_safety_copy_restored', $application, [
            'name' => $application->name,
            'copy' => $name,
        ]);

        return response()->json([
            'restored' => $name,
            'safety_copies' => $staging->safetyDumps($application),
        ]);
    }
}
