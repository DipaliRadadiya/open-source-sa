<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Sync\IgnoreSyncItemRequest;
use App\Http\Requests\Server\Sync\StartSyncRequest;
use App\Http\Resources\SyncRunResource;
use App\Jobs\RunServerSync;
use App\Models\SyncIgnore;
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
        //
        // `hasLiveRun` retires abandoned runs before answering. This used to be
        // a plain existence check, which meant a run left `pending` by a queue
        // worker that was not running, or `running` by a worker that was
        // killed, refused every later sync forever — the button was dead and no
        // screen could revive it.
        if (SyncRun::hasLiveRun()) {
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
                'include_ignored' => (bool) $request->validated('include_ignored', false),
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

        // A run whose worker died stops producing items but never changes
        // status, so the screen polling it spins for as long as someone leaves
        // it open. Retiring it here is what ends that.
        $run->failIfStale();

        $run->setRelation(
            'items',
            $run->items()->where('id', '>', $since)->orderBy('id')->limit(500)->get(),
        );

        return response()->json(['sync' => SyncRunResource::make($run)->resolve()]);
    }

    /**
     * Things the user has said no to, and the ability to change their mind.
     *
     * Without a way back, an ignore made by mistake is permanent from the
     * screen that made it — so the list is readable and an entry is
     * removable, both under the same permission that created it.
     */
    public function ignores(): JsonResponse
    {
        return response()->json([
            'ignores' => SyncIgnore::query()->latest('id')->get()->map(fn (SyncIgnore $ignore) => [
                'id' => $ignore->id,
                'resource_type' => $ignore->resource_type,
                'resource_key' => $ignore->resource_key,
                'note' => $ignore->note,
                'created_at' => $ignore->created_at?->format('d-m-Y H:i:s'),
            ])->all(),
        ]);
    }

    public function ignore(IgnoreSyncItemRequest $request, ActivityLogger $activity): JsonResponse
    {
        $validated = $request->validated();

        // updateOrCreate, not create: saying "ignore" twice is one decision,
        // and the unique index would otherwise turn a double-click into a 500.
        $ignore = SyncIgnore::updateOrCreate(
            [
                'resource_type' => $validated['resource_type'],
                'resource_key' => $validated['resource_key'],
            ],
            [
                'user_id' => Auth::id(),
                'note' => $validated['note'] ?? null,
            ],
        );

        $activity->log('sync.ignored', $ignore, [
            'resource_type' => $ignore->resource_type,
            'resource_key' => $ignore->resource_key,
        ]);

        return response()->json(['ignore' => ['id' => $ignore->id]], 201);
    }

    public function unignore(SyncIgnore $ignore, ActivityLogger $activity): JsonResponse
    {
        $activity->log('sync.unignored', $ignore, [
            'resource_type' => $ignore->resource_type,
            'resource_key' => $ignore->resource_key,
        ]);

        $ignore->delete();

        return response()->json(null, 204);
    }

    /** The most recent run, for a screen reopened after a refresh. */
    public function latest(): JsonResponse
    {
        $run = SyncRun::query()->latest('id')->first();

        // Reopening the screen is the usual way an abandoned run is found, so
        // it reports what happened rather than a progress bar that never moves.
        $run?->failIfStale();

        return response()->json([
            'sync' => $run === null ? null : SyncRunResource::make($run)->resolve(),
        ]);
    }
}
