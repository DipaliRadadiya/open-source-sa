<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiskCleanerRunResource;
use App\Models\DiskCleanerRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiskCleanerRunController extends Controller
{
    /**
     * Run history (manual + scheduled), most recent first.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $perPage = in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 20;

        $paginator = DiskCleanerRun::query()->latest()->paginate($perPage);

        return response()->json([
            'runs' => DiskCleanerRunResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
