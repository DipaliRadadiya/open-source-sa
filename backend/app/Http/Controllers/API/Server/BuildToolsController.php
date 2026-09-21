<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Jobs\InstallBuildTools;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\BuildTools\BuildToolsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BuildToolsController extends Controller
{
    /**
     * Whether this server can compile a native module, and what is missing.
     */
    public function index(BuildToolsManager $buildTools, InstallTracker $installs): JsonResponse
    {
        $install = $installs->current(InstallBuildTools::RUNTIME, InstallBuildTools::VERSION);

        return response()->json([
            'build_tools' => [
                'installed' => $buildTools->installed(),
                'missing' => $buildTools->missing(),
                // Only ever `installing` or `failed` — a finished install
                // deletes its row, so "installed" is answered by detection
                // above and there is no second copy of that fact to go stale.
                'install_status' => $install?->status?->value,
                'install_reason' => $install?->reason,
                'install_message' => $install?->message(),
            ],
        ]);
    }

    /**
     * Install the toolchain. `202` — the work is queued; poll `GET
     * /build-tools` and drive the UI from `install_status`.
     */
    public function install(BuildToolsManager $buildTools, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        if ($buildTools->installed()) {
            return response()->json(['message' => __('errors/build-tools.already_installed')], 422);
        }

        // Before dispatch, not inside the job: otherwise there is a window
        // between this 202 and a worker picking the job up where the install
        // exists and nothing can see it.
        $installs->start(InstallBuildTools::RUNTIME, InstallBuildTools::VERSION);

        InstallBuildTools::dispatch(Auth::id());
        $log->log('build_tools.install_started');

        return response()->json(['message' => __('build-tools.install_started')], 202);
    }
}
