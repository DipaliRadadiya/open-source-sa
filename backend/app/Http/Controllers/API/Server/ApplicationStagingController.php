<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\CreateStaging;
use App\Exceptions\Server\Application\ProvisioningFailedException;
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
use App\Services\Server\Disk\DiskSpaceCheck;
use App\Services\Server\Disk\SizeEstimate;
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

    public function store(CreateStagingRequest $request, Application $application, CreateStaging $action, DiskSpaceCheck $space, SizeEstimate $sizes): JsonResponse
    {
        // Room for a copy of the site and its database first (2026-10-29).
        $space->require(
            (int) $sizes->sum($sizes->files($application), $sizes->databases($application)),
            $application->rootPath(),
            'disk_space',
        );

        try {
            $staging = $action->execute($application, $request->domain());
        } catch (ProvisioningFailedException $e) {
            // Answered here, not left to the exception handler: that renders
            // a plain exception as "Server Error" whenever APP_DEBUG is off —
            // every real panel — so the step and the reference that ties it to
            // the server-ops log never reached the user. Still reported, so the
            // API error log keeps the failure.
            report($e);

            return response()->json([
                'message' => __('errors/application.staging_create_failed', ['step' => $e->step, 'reference' => $e->reference]),
                'step' => $e->step,
                'reason' => $e->reason,
                'reference' => $e->reference,
            ], 500);
        }

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
        return $this->start($application, 'push', $request->mode(), $request->wantsBackup(), $request->safetyCopy());
    }

    /** Update staging from live — on the queue, as a push is. */
    public function pull(PullStagingRequest $request, Application $application): JsonResponse
    {
        return $this->start($application, 'pull', $request->mode(), false, $request->safetyCopy());
    }

    public function run(Application $application, StagingRun $run): JsonResponse
    {
        abort_unless($run->application_id === $application->id, 404);

        return response()->json(['run' => StagingRunResource::make($run)->resolve()]);
    }

    private function start(Application $application, string $kind, string $mode, bool $backup, bool $safetyCopy = true): JsonResponse
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

        // Room first (2026-10-29): the files set aside while they are replaced,
        // the copy of the database being replaced when the safety copy is on,
        // and the live site's backup when one was asked for.
        $target = $kind === 'push' ? $application : $staging;

        if ($target !== null) {
            $sizes = app(SizeEstimate::class);
            $backupTarget = $backup ? $application->backupTarget : null;
            app(DiskSpaceCheck::class)->require((int) $sizes->sum(
                in_array($mode, ['files', 'full'], true) ? $sizes->files($target) : null,
                $safetyCopy && in_array($mode, ['database', 'full'], true) ? $sizes->databases($target) : null,
                $backupTarget !== null ? $sizes->backup($backupTarget) : null,
            ), $target->rootPath(), 'disk_space');
        }

        $run = StagingRun::create([
            'application_id' => $application->id,
            'staging_application_id' => $staging?->id,
            'kind' => $kind,
            'mode' => $mode,
            'backup' => $backup,
            'safety_copy' => $safetyCopy,
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
