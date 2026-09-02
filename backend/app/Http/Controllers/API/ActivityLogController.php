<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListMyActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\ActivityScopes;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Unlike the admin equivalent — which lists every action the system can
     * ever record, sourced from lang/activity.php — this is built from the
     * caller's actual rows. On a personal history an option that is
     * guaranteed to match nothing (a user who has never touched databases
     * seeing a "database" filter) is worse than a short list.
     *
     * Shape matches the admin endpoint so the frontend reuses one component.
     */
    public function filters(ListMyActivityLogRequest $request, ActivityScopes $scopes): JsonResponse
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
            // Only the scopes the caller actually has rows in — same reason
            // `types` is built from their history rather than the catalog.
            'scopes' => $scopes->options(
                $types->map(fn (string $type) => $scopes->for($type))->filter()->unique()->all(),
            ),
        ]);
    }

    /**
     * A user's own activity history — unlike the admin activity log
     * (Admin\ActivityLogController), this only ever shows the
     * authenticated user's own entries, never anyone else's, so the
     * redundant `user` field is omitted (no `with('user')` eager load —
     * ActivityLogResource's whenLoaded('user', ...) drops the key when
     * it's not loaded).
     */
    public function index(ListMyActivityLogRequest $request, ActivityScopes $scopes): JsonResponse
    {
        // The self-scope is applied first and unconditionally — no filter
        // combination can widen it to another user's rows.
        $query = ActivityLog::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at');

        // A set of types rather than one — `type` is indexed, so this
        // stays an index scan.
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

        // Free-text over type + action only. Unlike the admin log there is no
        // actor to search — every row here belongs to the caller.
        if ($search = $request->string('search')->trim()->value()) {
            ListSearch::apply($query, $search, ['type', 'action']);
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
     * Server-level events only: cronjob, disk_cleaner, service, fail2ban,
     * firewall, git_account, node, setting, panel_update. Per-app events
     * (application, database, backup) are surfaced through their own feature
     * and excluded here.
     *
     * Requires `activity_log` permission — different from `access-admin`
     * which gates the admin-wide log.
     */
    public function serverIndex(Request $request, ActivityScopes $scopes): JsonResponse
    {
        $query = ActivityLog::with('user:id,username')
            ->whereIn('type', [
                'cronjob', 'disk_cleaner', 'service', 'fail2ban',
                'firewall', 'git_account', 'node', 'setting', 'panel_update',
            ])
            ->latest('created_at');

        if ($type = $request->input('filter.type')) {
            $query->where('type', $type);
        }

        if ($action = $request->input('filter.action')) {
            $query->where('action', $action);
        }

        if ($search = $request->string('search')->trim()->value()) {
            ListSearch::apply($query, $search, ['type', 'action']);
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
