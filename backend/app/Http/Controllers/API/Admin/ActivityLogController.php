<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\ActivityCatalog;
use App\Services\ActivityKinds;
use App\Services\ActivityScopes;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;

class ActivityLogController extends Controller
{
    /**
     * Distinct `type`/`action` values, for populating a frontend filter
     * dropdown. Sourced from lang/activity.php (the single source of
     * truth for every known action) rather than a DISTINCT query on the
     * activity_log table, so the dropdown is fully populated even on a
     * fresh install with no activity yet.
     */
    public function filters(ActivityCatalog $catalog, ActivityScopes $scopes, ActivityKinds $kinds): JsonResponse
    {
        return response()->json($catalog->shape($catalog->keys()) + [
            // Both, always — the admin log is the whole catalog, so an option
            // with no rows behind it today is still the right option to offer.
            'scopes' => $scopes->options(),
            'kinds' => $kinds->options(),
        ]);
    }

    public function index(ListActivityLogRequest $request, ActivityScopes $scopes, ActivityKinds $kinds): JsonResponse
    {
        $query = ActivityLog::query()->with('user')->latest('created_at');

        if ($userId = $request->input('filter.user_id')) {
            $query->where('user_id', $userId);
        }

        if ($scope = $request->input('filter.scope')) {
            $query->whereIn('type', $scopes->types($scope));
        }

        // Both are now indexed exact-match columns.
        if ($action = $request->input('filter.action')) {
            $query->where('action', $action);
        }

        if ($type = $request->input('filter.type')) {
            $query->where('type', $type);
        }

        // FS-C15 / OLD-18.
        if ($kind = $request->input('filter.kind')) {
            $kinds->apply($query, $kind);
        }

        if ($request->boolean('filter.security')) {
            $kinds->applySecurity($query);
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($query) use ($search) {
                ActivityLog::search($query, $search);
                $query->orWhereHas(
                    'user',
                    fn ($user) => ListSearch::apply($user, $search, ['name', 'username']),
                );
            });
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
}
