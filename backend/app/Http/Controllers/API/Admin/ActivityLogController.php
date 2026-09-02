<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\ActivityScopes;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class ActivityLogController extends Controller
{
    /**
     * Distinct `type`/`action` values, for populating a frontend filter
     * dropdown. Sourced from lang/activity.php (the single source of
     * truth for every known action) rather than a DISTINCT query on the
     * activity_log table, so the dropdown is fully populated even on a
     * fresh install with no activity yet.
     */
    public function filters(ActivityScopes $scopes): JsonResponse
    {
        $keys = collect(Lang::get('activity'))->keys();

        $types = $keys->map(fn (string $key) => Str::before($key, '.'))->unique()->sort()->values();

        // actions grouped per type for dependent dropdowns, plus an `all`
        // deduped list for the initial "any type" view (no frontend merge).
        $perType = $keys
            ->groupBy(fn (string $key) => Str::before($key, '.'))
            ->map(fn ($group) => $group->map(fn (string $key) => Str::after($key, '.'))->unique()->sort()->values()->all());

        $all = $keys->map(fn (string $key) => Str::after($key, '.'))->unique()->sort()->values()->all();

        return response()->json([
            'types' => $types->all(),
            'actions' => ['all' => $all] + $perType->all(),
            // Both, always — the admin log is the whole catalog, so an option
            // with no rows behind it today is still the right option to offer.
            'scopes' => $scopes->options(),
        ]);
    }

    public function index(ListActivityLogRequest $request, ActivityScopes $scopes): JsonResponse
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

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($query) use ($search) {
                ListSearch::apply($query, $search, ['type', 'action']);
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
