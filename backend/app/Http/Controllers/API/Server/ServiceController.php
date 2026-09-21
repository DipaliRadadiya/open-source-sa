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
        // The same existence test the action path uses, rather than `find()`
        // alone. `find()` answers "is this a managed key", which said yes to a
        // compatibility alias — `mysql` on a MariaDB box — and to a service
        // that is not installed. Both then fell through to a 422 about
        // configuration, for a service the panel does not manage here.
        $entry = $services->find($service);

        abort_if($entry === null || $services->describe($entry) === null, 404);

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
