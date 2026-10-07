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
        // One clone of a site at a time. RunClone is unique per source, so a
        // second one was accepted with 202 and its job silently dropped: the
        // row sat at `pending` forever (CL-B1). A pending row whose job never
        // started is released first, so an old stuck one cannot block a site
        // for good.
        SiteClone::query()
            ->where('source_application_id', $application->id)
            ->where('status', CloneStatus::Pending)
            ->where('created_at', '<', now()->subMinutes(60))
            ->update(['status' => CloneStatus::Failed, 'reason' => 'abandoned', 'finished_at' => now()]);

        abort_if(
            SiteClone::query()
                ->where('source_application_id', $application->id)
                ->whereIn('status', [CloneStatus::Pending, CloneStatus::Running])
                ->exists(),
            409,
            __('clone.errors.already_running'),
        );

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
        // Loaded so the resource can report the copy's own deploy-on-push URL
        // once there is a copy. This is the endpoint the result screen polls,
        // and `whenLoaded` emits nothing without it — the field would be
        // permanently absent rather than occasionally null.
        return response()->json([
            'clone' => CloneResource::make($clone->load('targetApplication'))->resolve(),
        ]);
    }
}
