<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\DiskCleaner\RunCleanupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\DiskCleaner\CleanDiskRequest;
use App\Services\Server\DiskCleaner\DiskCleaner;
use Illuminate\Http\JsonResponse;

class DiskCleanerController extends Controller
{
    /**
     * Preview: disk usage + available cleanup categories with reclaimable
     * estimates and the exact paths each touches.
     */
    public function index(DiskCleaner $cleaner): JsonResponse
    {
        return response()->json($cleaner->preview());
    }

    /**
     * Clean the selected categories and report the space freed.
     */
    public function clean(CleanDiskRequest $request, RunCleanupAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated('categories')));
    }
}
