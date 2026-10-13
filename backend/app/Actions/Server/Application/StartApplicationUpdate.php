<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationUpdateStatus;
use App\Jobs\UpdateApplication;
use App\Models\Application;
use App\Models\ApplicationUpdate;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationUpdates;
use Illuminate\Validation\ValidationException;

class StartApplicationUpdate
{
    public function __construct(
        private ApplicationUpdates $updates,
        private ActivityLogger $activity,
    ) {}

    public function execute(Application $application, ?User $user): ApplicationUpdate
    {
        // A pending row whose job never started (worker down at the time) is
        // released first, so one stuck row cannot block the site for good.
        ApplicationUpdate::query()
            ->where('application_id', $application->id)
            ->where('status', ApplicationUpdateStatus::Pending)
            ->where('created_at', '<', now()->subMinutes(60))
            ->update(['status' => ApplicationUpdateStatus::Failed, 'reason' => 'abandoned', 'finished_at' => now()]);

        abort_if($this->updates->running($application) !== null, 409, __('app_update.errors.running'));

        $status = $this->updates->status($application);

        if ($status['blocked_reason'] !== null) {
            throw ValidationException::withMessages([
                'update' => __('app_update.errors.'.$status['blocked_reason'], ['version' => $status['installed_version'] ?? '']),
            ]);
        }

        $update = ApplicationUpdate::create([
            'application_id' => $application->id,
            'user_id' => $user?->id,
            'from_version' => $status['installed_version'],
            'to_version' => $status['latest_version'],
            'status' => ApplicationUpdateStatus::Pending,
        ]);

        UpdateApplication::dispatch($update->id, $application->id);

        $this->activity->log('application.update_started', $application, [
            'name' => $application->name,
            'from' => $update->from_version,
            'to' => $update->to_version,
        ], $user);

        return $update;
    }
}
