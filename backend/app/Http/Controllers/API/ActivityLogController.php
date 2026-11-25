<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListMyActivityLogRequest;
use App\Http\Requests\ListServerActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\ActivityCatalog;
use App\Services\ActivityKinds;
use App\Services\ActivityScopes;
use Illuminate\Http\JsonResponse;

/**
 * Handles three scopes of activity log, each behind its own permission:
 * - Own history (auth-gated)       — GET /activity-log
 * - Admin-wide (access-admin)       — GET /admin/activity-log
 * - Server-level (activity_log)     — GET /server/activity-log
 */
class ActivityLogController extends Controller
{
    /**
     * Filter options for the caller's own history.
     *
     * Unlike the admin equivalent (every action in lang/activity.php), this is built from the
     * caller's actual rows, so no option is guaranteed to match nothing.
     *
     * Same shape as the admin endpoint so the frontend reuses one component.
     */
    public function filters(ListMyActivityLogRequest $request, ActivityScopes $scopes, ActivityKinds $kinds): JsonResponse
    {
        $pairs = ActivityLog::query()
            ->where('user_id', $request->user()->id)
            ->select('type', 'action')
            ->distinct()
            ->get();

        $types = $pairs->pluck('type')->unique()->sort()->values();

        $perType = $pairs
            ->groupBy('type')
            ->map(fn ($group) => $group->pluck('action')->unique()->sort()->values()->all());

        $all = $pairs->pluck('action')->unique()->sort()->values()->all();

        return response()->json([
            'types' => $types->all(),
            'actions' => ['all' => $all] + $perType->all(),
            // Only scopes the caller has rows in, like `types`.
            'scopes' => $scopes->options(
                $types->map(fn (string $type) => $scopes->for($type))->filter()->unique()->all(),
            ),
            'kinds' => $kinds->options(),
        ]);
    }

    /**
     * A user's own activity history. Unlike the admin log (Admin\ActivityLogController) it only
     * shows the caller's entries, so the `user` field is omitted (no `with('user')` eager load;
     * ActivityLogResource drops the key when it is not loaded).
     */
    public function index(ListMyActivityLogRequest $request, ActivityScopes $scopes, ActivityKinds $kinds): JsonResponse
    {
        // The self-scope is applied first, so no filter can widen it to another user's rows.
        $query = ActivityLog::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at');

        // A set of types; `type` is indexed, so this stays an index scan.
        if ($scope = $request->input('filter.scope')) {
            $query->whereIn('type', $scopes->types($scope));
        }

        // Exact matches on the indexed columns, same as the admin log.
        if ($type = $request->input('filter.type')) {
            $query->where('type', $type);
        }

        if ($action = $request->input('filter.action')) {
            $query->where('action', $action);
        }

        if ($kind = $request->input('filter.kind')) {
            $kinds->apply($query, $kind);
        }

        if ($request->boolean('filter.security')) {
            $kinds->applySecurity($query);
        }

        // No actor to search: every row belongs to the caller.
        if ($search = $request->string('search')->trim()->value()) {
            ActivityLog::search($query, $search);
        }

        $perPage = (int) $request->input('per_page', 10);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'activity_log' => ActivityLogResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Filter options for the server log, for someone with `activity_log` but no admin access.
     * The catalog limited to the server scope, so no option is one this log cannot show.
     */
    public function serverFilters(ActivityCatalog $catalog, ActivityScopes $scopes, ActivityKinds $kinds): JsonResponse
    {
        return response()->json($catalog->shape($catalog->keys($scopes->types('server'))) + [
            'kinds' => $kinds->options(),
        ]);
    }

    /**
     * The server's events — everything in the `server` scope of
     * config/activity.php, sites and databases included.
     *
     * The types come from the same source as the admin log's scope filter; a separate list drifted
     * and hid events from non-admins.
     *
     * Requires `activity_log` permission, not `access-admin` (which gates the admin-wide log).
     */
    public function serverIndex(ListServerActivityLogRequest $request, ActivityScopes $scopes, ActivityKinds $kinds): JsonResponse
    {
        $query = ActivityLog::with('user:id,username')
            ->whereIn('type', $scopes->types('server'))
            ->latest('created_at');

        if ($type = $request->input('filter.type')) {
            $query->where('type', $type);
        }

        if ($action = $request->input('filter.action')) {
            $query->where('action', $action);
        }

        if ($kind = $request->input('filter.kind')) {
            $kinds->apply($query, $kind);
        }

        if ($request->boolean('filter.security')) {
            $kinds->applySecurity($query);
        }

        if ($search = $request->string('search')->trim()->value()) {
            ActivityLog::search($query, $search);
        }

        $perPage = (int) $request->input('per_page', 20);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'activity_log' => ActivityLogResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
