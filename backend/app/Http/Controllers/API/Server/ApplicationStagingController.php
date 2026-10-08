<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\CreateStaging;
use App\Actions\Server\Application\PushStaging;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\CreateStagingRequest;
use App\Http\Requests\Server\Application\PushStagingRequest;
use App\Http\Requests\Server\Application\RestoreStagingSafetyCopyRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\BackupResource;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\StagingManager;
use Illuminate\Http\JsonResponse;

class ApplicationStagingController extends Controller
{
    public function show(Application $application): JsonResponse
    {
        return response()->json([
            'staging' => $application->staging
                ? ApplicationResource::make($application->staging->load('systemUser'))->resolve()
                : null,
        ]);
    }

    public function store(CreateStagingRequest $request, Application $application, CreateStaging $action): JsonResponse
    {
        $staging = $action->execute($application, $request->domain());

        return response()->json([
            'staging' => ApplicationResource::make($staging->load('systemUser'))->resolve(),
        ], 201);
    }

    public function push(PushStagingRequest $request, Application $application, PushStaging $action): JsonResponse
    {
        $backup = $action->execute($application, $request->mode(), $request->wantsBackup(), $request->user());

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
            // FS-B10: the backup taken first, or null when none was asked for.
            'backup' => $backup ? BackupResource::make($backup)->resolve() : null,
        ]);
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
