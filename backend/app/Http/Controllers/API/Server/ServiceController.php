<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Service\RunServiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Service\ServiceActionRequest;
use App\Services\Server\ServiceManager;
use Illuminate\Http\JsonResponse;

class ServiceController extends Controller
{
    /**
     * Managed + installed services with live systemd status.
     */
    public function index(ServiceManager $services): JsonResponse
    {
        return response()->json([
            'services' => $services->list(),
        ]);
    }

    /**
     * Run an action (start/stop/restart/reload/enable/disable) on a service.
     */
    public function update(ServiceActionRequest $request, string $service, RunServiceAction $action): JsonResponse
    {
        return response()->json([
            'service' => $action->execute($service, $request->validated('action')),
        ]);
    }
}
