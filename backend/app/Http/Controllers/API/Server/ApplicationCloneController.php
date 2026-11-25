<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\CloneStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\CreateCloneRequest;
use App\Http\Resources\CloneResource;
use App\Jobs\RunClone;
use App\Models\Application;
use App\Models\SiteClone;
use App\Services\Server\Disk\DiskSpaceCheck;
use App\Services\Server\Disk\SizeEstimate;
use Illuminate\Http\JsonResponse;

class ApplicationCloneController extends Controller
{
    /**
     * Every clone across every application, newest first.
     *
     * Lets another browser session resume a clone; polling needs the clone id.
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
     * Returns 202 at once; the cloning runs on the queue so the request cannot time out
     * during the rsync. Poll GET /api/clones/{id} for progress.
     */
    public function store(CreateCloneRequest $request, Application $application): JsonResponse
    {
        // One clone per site at a time: RunClone is unique per source, so a second job
        // would be dropped silently. Stale pending rows are released first.
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

        // Room for a copy of the site and its databases first.
        $sizes = app(SizeEstimate::class);
        app(DiskSpaceCheck::class)->require(
            (int) $sizes->sum($sizes->files($application), $sizes->databases($application)),
            $application->rootPath(),
            'disk_space',
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
        // Loaded so the resource can report the copy's deploy-on-push URL (`whenLoaded`).
        return response()->json([
            'clone' => CloneResource::make($clone->load('targetApplication'))->resolve(),
        ]);
    }
}
