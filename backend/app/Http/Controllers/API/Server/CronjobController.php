<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Cronjob\CreateCronjob;
use App\Actions\Server\Cronjob\DeleteCronjob;
use App\Actions\Server\Cronjob\UpdateCronjob;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Cronjob\StoreCronjobRequest;
use App\Http\Requests\Server\Cronjob\UpdateCronjobRequest;
use App\Http\Resources\CronjobResource;
use App\Models\Cronjob;
use App\Support\CronSchedulePresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CronjobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Cronjob::query()->with('systemUser:id,username')->latest();

        if ($systemUserId = $request->input('filter.system_user_id')) {
            $query->where('system_user_id', $systemUserId);
        }

        if ($username = $request->input('filter.username')) {
            $query->where('username', $username);
        }

        if ($request->has('filter.active')) {
            $query->where('active', $request->boolean('filter.active'));
        }

        $perPage = (int) $request->input('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 10;
        $paginator = $query->paginate($perPage);

        return response()->json([
            'cronjobs' => CronjobResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Schedule presets for the frontend dropdown (single source of truth).
     */
    public function schedulePresets(): JsonResponse
    {
        return response()->json([
            'presets' => CronSchedulePresets::all(),
        ]);
    }

    public function store(StoreCronjobRequest $request, CreateCronjob $action): JsonResponse
    {
        $cronjob = $action->execute($request->validated());

        return response()->json([
            'cronjob' => CronjobResource::make($cronjob->load('systemUser'))->resolve(),
        ], 201);
    }

    public function show(Cronjob $cronjob): JsonResponse
    {
        return response()->json([
            'cronjob' => CronjobResource::make($cronjob->load('systemUser'))->resolve(),
        ]);
    }

    public function update(UpdateCronjobRequest $request, Cronjob $cronjob, UpdateCronjob $action): JsonResponse
    {
        $cronjob = $action->execute($cronjob, $request->validated());

        return response()->json([
            'cronjob' => CronjobResource::make($cronjob->load('systemUser'))->resolve(),
        ]);
    }

    public function destroy(Cronjob $cronjob, DeleteCronjob $action): JsonResponse
    {
        $action->execute($cronjob);

        return response()->json(null, 204);
    }
}
