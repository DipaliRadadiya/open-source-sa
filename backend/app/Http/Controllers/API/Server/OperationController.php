<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Operation\IndexOperationsRequest;
use App\Services\Operations\OperationFeed;
use Illuminate\Http\JsonResponse;

class OperationController extends Controller
{
    /**
     * Every long job, running now and recently finished, in one list (RC-20).
     * One endpoint rather than one per kind: a header polling eleven of them
     * would spend the whole per-minute API budget on its own.
     */
    public function index(IndexOperationsRequest $request, OperationFeed $feed): JsonResponse
    {
        $active = $request->boolean('active');
        $hours = (int) $request->validated('hours', 24);

        $operations = $feed->for($request->user(), $active, now()->subHours($hours));

        return response()->json([
            'operations' => $operations,
            'meta' => [
                'running' => $operations->whereIn('state', ['waiting', 'running'])->count(),
                'hours' => $active ? null : $hours,
            ],
        ]);
    }
}
