<?php

namespace App\Http\Controllers\API\Admin;

use App\Actions\Admin\PanelUpdate\QueuePanelUpdate;
use App\Enums\PanelUpdateStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PanelUpdateResource;
use App\Models\PanelUpdate;
use App\Services\Panel\InstalledPanelInfo;
use Illuminate\Http\JsonResponse;

/**
 * Administrator-only panel-update endpoints.
 *
 * The resource itself is the current update row (the most recent one, with
 * `pending`/`running` rows always winning over terminal ones), plus a
 * sibling `installed` snapshot telling the admin what is on disk right now.
 *
 * The two are siblings rather than nested because `installed` is *what is
 * there*, and the panel-update row is *what was requested* — the same split
 * the activity log uses for actor vs subject, so a failed update can still
 * report an honest "currently installed" without confusing it with the
 * attempt that failed.
 */
class PanelUpdateController extends Controller
{
    /**
     * The current state of the panel from an admin's point of view: the
     * latest update row (in-flight if one exists, otherwise newest terminal)
     * plus the installed version/commit snapshot.
     */
    public function index(InstalledPanelInfo $installed): JsonResponse
    {
        $latest = $this->latest();

        return response()->json([
            'panel_update' => $latest === null
                ? null
                : PanelUpdateResource::make($latest->load('user'))->resolve(),
            'installed' => $installed->installed(),
        ]);
    }

    /**
     * Queue a panel update. Returns 202 with the row already created, so the
     * screen has something to show and poll before a worker picks the job up.
     *
     * `202` says the work is queued rather than done — the same shape the
     * deploy/store endpoint returns. The cache lock + DB guard inside the
     * action turn a duplicate click into a 409, not into a second row.
     */
    public function store(QueuePanelUpdate $action): JsonResponse
    {
        $update = $action->execute();

        return response()->json([
            'panel_update' => PanelUpdateResource::make($update->load('user'))->resolve(),
            'message' => __('panel_update.queued'),
        ], 202);
    }

    /**
     * Newest row the screen cares about. An in-flight row always wins over a
     * terminal one, because the user is looking at it — the most recent
     * `succeeded` from last week is not what they want to see when they came
     * back to find their update still going.
     */
    private function latest(): ?PanelUpdate
    {
        $inFlight = PanelUpdate::query()
            ->with('user')
            ->whereIn('status', [
                PanelUpdateStatus::Pending->value,
                PanelUpdateStatus::Running->value,
            ])
            ->latest('id')
            ->first();

        if ($inFlight !== null) {
            return $inFlight;
        }

        return PanelUpdate::query()
            ->with('user')
            ->latest('id')
            ->first();
    }
}