<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivityRetentionRequest;
use App\Services\Activity\ActivityRetention;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;

/**
 * The activity log's "delete entries older than" choice (2026-10-29).
 */
class ActivityRetentionController extends Controller
{
    public function show(ActivityRetention $retention): JsonResponse
    {
        return response()->json(['retention' => $this->payload($retention)]);
    }

    public function update(ActivityRetentionRequest $request, ActivityRetention $retention, ActivityLogger $activity): JsonResponse
    {
        $months = $request->months();
        $retention->set($months);

        // Two literal calls, not one with a computed name, so the activity
        // placeholder check can read what each one records.
        if ($months === null) {
            $activity->log('setting.activity_retention_cleared');
        } else {
            $activity->log('setting.activity_retention_updated', properties: ['months' => $months]);
        }

        return response()->json(['retention' => $this->payload($retention)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ActivityRetention $retention): array
    {
        return [
            // null = keep everything, the default.
            'months' => $retention->months(),
            'choices' => ActivityRetention::CHOICES,
            ...$retention->stats(),
        ];
    }
}
