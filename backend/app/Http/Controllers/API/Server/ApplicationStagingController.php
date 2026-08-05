<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\CreateStaging;
use App\Actions\Server\Application\PushStaging;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\CreateStagingRequest;
use App\Http\Requests\Server\Application\PushStagingRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
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
        $action->execute($application, $request->mode());

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ]);
    }
}
