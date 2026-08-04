<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\Panel\AvailableRelease;
use App\Services\Panel\InstalledPanelInfo;
use App\Services\Panel\UpdatePreflight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of the panel's update state.
 *
 * Nothing here mutates anything: no download, no checkout, no service
 * restart. It answers three questions — what am I running, what is published,
 * and could an update run here — and leaves applying it to the operator.
 */
class PanelUpdateController extends Controller
{
    public function show(
        Request $request,
        InstalledPanelInfo $installed,
        AvailableRelease $releases,
        UpdatePreflight $preflight,
    ): JsonResponse {
        $current = $installed->installed();

        // `?refresh=1` bypasses the cache for the "check now" button. The
        // route is throttled so this cannot be used to hammer the release host.
        $latest = $releases->latest(fresh: $request->boolean('refresh'));

        return response()->json([
            'panel_update' => [
                'installed' => $current,
                'available' => $latest,
                'update_available' => $releases->isNewer($current['version'], $latest['version']),
                'preflight' => $preflight->run(),
            ],
        ]);
    }
}
