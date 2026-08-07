<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\CloneStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\CreateCloneRequest;
use App\Http\Resources\CloneResource;
use App\Jobs\RunClone;
use App\Models\Application;
use App\Models\Clone as CloneModel;
use Illuminate\Http\JsonResponse;

class ApplicationCloneController extends Controller
{
    /**
     * Start a clone.
     *
     * 202 with the Clone record immediately — the actual cloning runs on the
     * queue so the HTTP request cannot time out while files are still being
     * rsynced across. Polling GET /api/clones/{id} shows progress via named
     * steps.
     */
    public function store(CreateCloneRequest $request, Application $application): JsonResponse
    {
        $clone = CloneModel::create([
            'source_application_id' => $application->id,
            'user_id' => $request->user()?->id,
            'name' => $request->validated('name'),
            'domain' => $request->domain(),
            'status' => CloneStatus::Pending,
        ]);

        RunClone::dispatch($clone->id)->onQueue('default');

        return response()->json([
            'clone' => CloneResource::make($clone)->resolve(),
        ], 202);
    }

    /** Poll a clone while it runs. */
    public function show(CloneModel $clone): JsonResponse
    {
        return response()->json([
            'clone' => CloneResource::make($clone)->resolve(),
        ]);
    }
}
