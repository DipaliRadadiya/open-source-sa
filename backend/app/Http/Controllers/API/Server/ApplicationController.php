<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\CreateApplication;
use App\Actions\Server\Application\DeleteApplication;
use App\Actions\Server\Application\UpdateApplication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\StoreApplicationRequest;
use App\Http\Requests\Server\Application\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;

class ApplicationController extends Controller
{
    public function index(): JsonResponse
    {
        $applications = Application::query()->with('systemUser')->latest('id')->get();

        return response()->json([
            'applications' => ApplicationResource::collection($applications)->resolve(),
        ]);
    }

    public function store(StoreApplicationRequest $request, CreateApplication $action): JsonResponse
    {
        $application = $action->execute($request->validated());

        return response()->json([
            'application' => ApplicationResource::make($application)->resolve(),
        ], 201);
    }

    public function show(Application $application): JsonResponse
    {
        return response()->json([
            'application' => ApplicationResource::make($application->load('systemUser'))->resolve(),
        ]);
    }

    public function update(Application $application, UpdateApplicationRequest $request, UpdateApplication $action): JsonResponse
    {
        $application = $action->execute($application, $request->validated());

        return response()->json([
            'application' => ApplicationResource::make($application)->resolve(),
        ]);
    }

    public function destroy(Application $application, DeleteApplication $action): JsonResponse
    {
        $action->execute($application);

        return response()->json(['deleted' => true]);
    }
}
