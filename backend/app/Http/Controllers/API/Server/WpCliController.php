<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Jobs\InstallWpCli;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\WpCli\WpCli;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class WpCliController extends Controller
{
    /**
     * Install wp-cli. `202` — the work is queued; the setup page shows it as
     * installed once the binary is on the box.
     */
    public function install(WpCli $wpCli, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        if ($wpCli->installed()) {
            return response()->json(['message' => __('errors/wp-cli.already_installed')], 422);
        }

        // Before dispatch, so the install is visible from the moment of the 202.
        $installs->start(InstallWpCli::RUNTIME, InstallWpCli::VERSION);

        InstallWpCli::dispatch(Auth::id());
        $log->log('wp_cli.install_started');

        return response()->json(['message' => __('wp-cli.install_started')], 202);
    }
}
