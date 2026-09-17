<?php

namespace App\Http\Resources;

use App\Models\DiskCleanerSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DiskCleanerSchedule */
class DiskCleanerScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $nextRun = $this->nextRunAt();
        $nextRun = $nextRun === null ? null : Carbon::instance($nextRun);

        return [
            'enabled' => (bool) $this->enabled,
            'frequency' => $this->frequency,
            'categories' => $this->categories ?? [],
            'threshold_percent' => $this->threshold_percent,
            'last_run_at' => $this->last_run_at?->format('d-m-Y H:i:s'),
            'last_run_at_human' => $this->last_run_at?->diffForHumans(),
            // When it will next run, and in which clock. This screen carried
            // neither: it said "weekly" and left the user to discover the hour
            // (03:00) by watching for it. Null when the cleaner is off, rather
            // than naming a run that will not happen.
            'next_run_at' => $nextRun?->format('d-m-Y H:i:s'),
            'next_run_at_human' => $nextRun?->diffForHumans(),
            'timezone' => $this->scheduleTimezone(),
        ];
    }
}
