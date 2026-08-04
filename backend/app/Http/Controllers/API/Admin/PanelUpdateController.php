<?php

namespace App\Http\Controllers\API\Admin;

use App\Actions\Admin\PanelUpdate\QueuePanelUpdate;
use App\Http\Controllers\Controller;
use App\Http\Resources\PanelUpdateResource;
use App\Models\PanelUpdate;
use App\Services\Panel\AvailableRelease;
use App\Services\Panel\InstalledPanelInfo;
use App\Services\Panel\PanelUpdateRunner;
use App\Services\Panel\UpdatePreflight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelUpdateController extends Controller
{
    /**
     * What am I running, what is published, could an update run here, and is
     * one happening now. Read-only.
     */
    public function show(
        Request $request,
        InstalledPanelInfo $installed,
        AvailableRelease $releases,
        UpdatePreflight $preflight,
        PanelUpdateRunner $runner,
    ): JsonResponse {
        $current = $installed->installed();

        // `?refresh=1` bypasses the cache for the "check now" button. The
        // route is throttled so it cannot be used to hammer the release host.
        $latest = $releases->latest(fresh: $request->boolean('refresh'));

        $latestRun = PanelUpdate::query()->latest('id')->first();

        if ($latestRun !== null) {
            // Read progress off the state file before answering. An update
            // that finished after php-fpm restarted has nobody left alive to
            // have recorded it; this is where it gets noticed.
            $latestRun = $runner->reconcile($latestRun);
        }

        return response()->json([
            'panel_update' => [
                'installed' => $current,
                'available' => $latest,
                'update_available' => $releases->isNewer($current['version'], $latest['version']),
                'preflight' => $preflight->run(),
                'latest_run' => $latestRun === null
                    ? null
                    : PanelUpdateResource::make($latestRun)->resolve(),
            ],
        ]);
    }

    /**
     * Start an update. Returns immediately — the runner is detached and the
     * panel is about to restart itself, so there is nothing to wait for.
     */
    public function store(Request $request, QueuePanelUpdate $action): JsonResponse
    {
        $update = $action->execute(
            $request->user(),
            // Renders and runs the script with every mutating command echoed
            // instead of executed. The only safe way to exercise this on a
            // box you care about.
            dryRun: $request->boolean('dry_run'),
        );

        return response()->json([
            'panel_update' => PanelUpdateResource::make($update)->resolve(),
        ], 202);
    }

    /**
     * Poll one run. Separate from the index because the frontend polls this
     * every couple of seconds while the bar moves, and it must not drag the
     * release-host check along with it.
     */
    public function status(PanelUpdate $panelUpdate, PanelUpdateRunner $runner): JsonResponse
    {
        return response()->json([
            'panel_update' => PanelUpdateResource::make($runner->reconcile($panelUpdate))->resolve(),
        ]);
    }
}
