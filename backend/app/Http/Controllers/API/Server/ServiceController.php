<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Service\RunServiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Service\ServiceActionRequest;
use App\Services\Server\ConfigTester;
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
     * Validate a service's configuration.
     *
     * Read-only on purpose: this tells you whether a reload would be safe, it
     * does not perform one. `422` for a service with no meaningful test rather
     * than inventing a command that proves nothing.
     */
    public function configTest(string $service, ServiceManager $services, ConfigTester $tester): JsonResponse
    {
        abort_if($services->find($service) === null, 404);

        $result = $tester->test($service);

        abort_if($result === null, 422, __('errors/service.not_testable'));

        return response()->json(['config_test' => $result]);
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
