<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\StartApplicationUpdate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\StartApplicationUpdateRequest;
use App\Http\Resources\ApplicationUpdateResource;
use App\Models\Application;
use App\Models\ApplicationUpdate;
use App\Services\Server\Applications\ApplicationUpdates;
use Illuminate\Http\JsonResponse;

/** One-click update of a site's own software (Moodle, Mautic, Statamic, Craft CMS, Uptime Kuma, phpMyAdmin). */
class ApplicationUpdateController extends Controller
{
    /** Installed and available versions, whether an update can start, and the recent runs. Also the poll. */
    public function show(Application $application, ApplicationUpdates $updates): JsonResponse
    {
        $history = ApplicationUpdate::with('user:id,username')
            ->where('application_id', $application->id)
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'update' => $updates->status($application->loadMissing('systemUser')),
            'history' => ApplicationUpdateResource::collection($history)->resolve(),
        ]);
    }

    /** Start the update. 202: it runs on the queue; poll GET until it finishes. */
    public function store(StartApplicationUpdateRequest $request, Application $application, StartApplicationUpdate $start): JsonResponse
    {
        $update = $start->execute($application->loadMissing('systemUser'), $request->user());

        return response()->json([
            'update' => ApplicationUpdateResource::make($update->load('user:id,username'))->resolve(),
        ], 202);
    }
}
