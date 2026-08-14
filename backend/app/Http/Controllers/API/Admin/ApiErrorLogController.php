<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListApiErrorLogRequest;
use App\Http\Resources\ApiErrorLogResource;
use App\Services\Admin\ApiErrorLogReader;
use Illuminate\Http\JsonResponse;

class ApiErrorLogController extends Controller
{
    public function index(ListApiErrorLogRequest $request, ApiErrorLogReader $logs): JsonResponse
    {
        $result = $logs->latest((int) $request->validated('lines', ApiErrorLogReader::DEFAULT_LINES));

        return response()->json([
            'error_logs' => ApiErrorLogResource::collection($result['entries'])->resolve(),
            'meta' => ['truncated' => $result['truncated']],
        ]);
    }
}
