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
use App\Services\Server\Applications\LegacyHandover;
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
        // An omitted `mode` must never resolve to the one that writes.
        $mode = SyncMode::from((string) $request->validated('mode', SyncMode::Preview->value));

        // One at a time: the job is unique, so a second dispatch would be silently dropped.
        // `hasLiveRun` retires abandoned runs first, so a dead worker cannot block syncs forever.
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
     * Finish taking a migrated server over: the writes Sync deliberately does not make.
     *
     * `discover()` and `adopt()` must never touch the server, so retiring the old agent, repairing
     * boot persistence and giving PM2's logs a rotation policy are a separate, explicit decision.
     * None of it restarts an application.
     */
    public function handover(LegacyHandover $handover, ActivityLogger $activity): JsonResponse
    {
        $result = $handover->complete();

        $activity->log('sync.legacy_handover', null, [
            'agent' => $result['agent']['unit'] ?? null,
            'users' => array_column($result['users'], 'username'),
        ]);

        // 500 with the per-step results on failure, so a half-done handover is not read as "Done".
        return response()->json($result, $result['ok'] ? 200 : 500);
    }

    public function handoverStatus(LegacyHandover $handover): JsonResponse
    {
        return response()->json($handover->status());
    }

    /**
     * A run and its items after a cursor: the client polls with the id of its last item and
     * appends what comes back.
     */
    public function show(Request $request, SyncRun $run): JsonResponse
    {
        $since = (int) $request->query('since', 0);

        // A run whose worker died never changes status; retire it so the polling screen stops.
        $run->failIfStale();

        $run->setRelation(
            'items',
            $run->items()->where('id', '>', $since)->orderBy('id')->limit(500)->get(),
        );

        return response()->json(['sync' => SyncRunResource::make($run)->resolve()]);
    }

    /**
     * Things the user has said no to; entries are removable under the same permission that
     * created them.
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

        // updateOrCreate: the unique index would turn a double-click into a 500.
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

        // Reopening the screen is how an abandoned run is usually found; retire it.
        $run?->failIfStale();

        return response()->json([
            'sync' => $run === null ? null : SyncRunResource::make($run)->resolve(),
        ]);
    }
}
