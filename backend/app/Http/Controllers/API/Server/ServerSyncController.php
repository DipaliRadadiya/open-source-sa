<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Sync\StartSyncRequest;
use App\Http\Resources\SyncRunResource;
use App\Jobs\RunServerSync;
use App\Models\SyncRun;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Reading a migrated server into the panel.
 *
 * Two modes: `preview` reads and adopts nothing, `apply` writes the rows.
 * Preview is the default and the intended first step — the point of the
 * feature is that a user can see the whole list, with the evidence behind
 * each guess, before anything is created.
 */
class ServerSyncController extends Controller
{
    public function store(StartSyncRequest $request, ActivityLogger $activity): JsonResponse
    {
        // Not the request's default: an omitted `mode` must never resolve to
        // the one that writes.
        $mode = SyncMode::from((string) $request->validated('mode', SyncMode::Preview->value));

        // One at a time. The job is unique, so a second dispatch would be
        // silently dropped — better to say so than to hand back a run id that
        // never starts.
        if (SyncRun::query()->whereIn('status', [SyncStatus::Pending, SyncStatus::Running])->exists()) {
            throw ValidationException::withMessages([
                'sync' => [__('sync.errors.already_running')],
            ]);
        }

        $run = SyncRun::create([
            'user_id' => Auth::id(),
            'mode' => $mode,
            'status' => SyncStatus::Pending,
            'options' => [
                'only' => $request->validated('only', []),
                'include_firewall' => (bool) $request->validated('include_firewall', false),
            ],
        ]);

        RunServerSync::dispatch($run->id);

        $activity->log('sync.started', $run, ['mode' => $mode->value]);

        return response()->json(['sync' => SyncRunResource::make($run)->resolve()], 202);
    }

    /**
     * A run and its items after a cursor.
     *
     * The cursor is what makes this a live feed: the client polls with the id
     * of the last item it has, and appends what comes back. Returning the
     * whole list every second would re-send a thousand rows to add three.
     */
    public function show(Request $request, SyncRun $run): JsonResponse
    {
        $since = (int) $request->query('since', 0);

        $run->setRelation(
            'items',
            $run->items()->where('id', '>', $since)->orderBy('id')->limit(500)->get(),
        );

        return response()->json(['sync' => SyncRunResource::make($run)->resolve()]);
    }

    /** The most recent run, for a screen reopened after a refresh. */
    public function latest(): JsonResponse
    {
        $run = SyncRun::query()->latest('id')->first();

        return response()->json([
            'sync' => $run === null ? null : SyncRunResource::make($run)->resolve(),
        ]);
    }
}
