<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
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
    public function filters(): JsonResponse
    {
        $actions = collect(Lang::get('activity'))->keys()->sort()->values();
        $types = $actions->map(fn (string $action) => Str::before($action, '.'))->unique()->sort()->values();

        return response()->json([
            'types' => $types->all(),
            'actions' => $actions->all(),
        ]);
    }

    public function index(ListActivityLogRequest $request): JsonResponse
    {
        $query = ActivityLog::query()->with('user')->latest('created_at');

        if ($userId = $request->input('filter.user_id')) {
            $query->where('user_id', $userId);
        }

        if ($action = $request->input('filter.action')) {
            $query->where('action', $action);
        }

        if ($type = $request->input('filter.type')) {
            $query->where('action', 'like', $type.'.%');
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
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
