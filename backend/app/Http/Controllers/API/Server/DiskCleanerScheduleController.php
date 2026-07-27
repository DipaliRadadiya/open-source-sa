<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\DiskCleaner\UpdateScheduleRequest;
use App\Http\Resources\DiskCleanerScheduleResource;
use App\Models\DiskCleanerSchedule;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DiskCleanerScheduleController extends Controller
{
    /**
     * The automatic-cleaner profile (defaults when none is set yet).
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'schedule' => new DiskCleanerScheduleResource(DiskCleanerSchedule::current()),
        ]);
    }

    /**
     * Create or update the automatic-cleaner profile. This DB row is the
     * single source of truth the scheduler reads — edits/disables take effect
     * on the next tick (no cron file to rewrite, no drift).
     */
    public function update(UpdateScheduleRequest $request, ActivityLogger $activityLogger): JsonResponse
    {
        $schedule = DiskCleanerSchedule::query()->first() ?? new DiskCleanerSchedule;
        $schedule->fill($request->validated())->save();

        $activityLogger->log('disk_cleaner.schedule_updated', null, [
            'enabled' => $schedule->enabled ? 'on' : 'off',
        ]);

        return response()->json([
            'schedule' => new DiskCleanerScheduleResource($schedule),
        ]);
    }

    /**
     * Remove the schedule entirely (vs. disabling it).
     */
    public function destroy(ActivityLogger $activityLogger): JsonResponse
    {
        DiskCleanerSchedule::query()->delete();

        $activityLogger->log('disk_cleaner.schedule_updated', null, ['enabled' => 'off']);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
