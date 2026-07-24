<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListMyActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;

class ActivityLogController extends Controller
{
    /**
     * A user's own activity history — unlike the admin activity log
     * (Admin\ActivityLogController), this only ever shows the
     * authenticated user's own entries, never anyone else's, so the
     * redundant `user` field is omitted (no `with('user')` eager load —
     * ActivityLogResource's whenLoaded('user', ...) drops the key when
     * it's not loaded).
     */
    public function index(ListMyActivityLogRequest $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);

        $paginator = ActivityLog::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->paginate($perPage);

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
