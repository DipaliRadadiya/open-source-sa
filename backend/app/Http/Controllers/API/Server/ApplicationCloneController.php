<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\CloneStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\CreateCloneRequest;
use App\Http\Resources\CloneResource;
use App\Jobs\RunClone;
use App\Models\Application;
use App\Models\SiteClone;
use Illuminate\Http\JsonResponse;

class ApplicationCloneController extends Controller
{
    /**
     * Every clone across every application, newest first.
     *
     * For resuming a clone from a different browser session than the one that
     * started it — the poll endpoint only works for the session that knows the
     * clone id.
     */
    public function index(): JsonResponse
    {
        $clones = SiteClone::with('sourceApplication:id,name,domain', 'user:id,username')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'clones' => CloneResource::collection($clones)->resolve(),
            'meta' => [
                'current_page' => $clones->currentPage(),
                'per_page' => $clones->perPage(),
                'total' => $clones->total(),
                'last_page' => $clones->lastPage(),
            ],
        ]);
    }

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
        $clone = SiteClone::create([
            'source_application_id' => $application->id,
            'user_id' => $request->user()?->id,
            'name' => $request->validated('name'),
            'domain' => $request->domain(),
            'status' => CloneStatus::Pending,
        ]);

        RunClone::dispatch($clone->id, $application->id);

        return response()->json([
            'clone' => CloneResource::make($clone)->resolve(),
        ], 202);
    }

    /** Poll a clone while it runs. */
    public function show(SiteClone $clone): JsonResponse
    {
        return response()->json([
            'clone' => CloneResource::make($clone)->resolve(),
        ]);
    }
}
