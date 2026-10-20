<?php

namespace App\Services\Server\Applications;

use App\Enums\ApplicationStatus;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Closes out a site whose install job is provably gone (2026-10-18).
 *
 * A site leaves `provisioning` when the job finishes, fails, or
 * `ProvisionApplication::failed()` runs. None of these happens when the worker
 * dies without warning, or when it cannot write the answer: on a test server
 * whose disk filled, three Docker installs died and stayed `provisioning` for
 * good. Delete refuses a provisioning site, so the user could never remove one
 * either — "the server is busy", indefinitely, with nothing to do about it.
 *
 * Only a site whose job really started (`provisioning_started_at`), and only
 * once its own time budget plus the unique-lock grace has passed: by then the
 * worker has killed the job and the lock that stops a second one has expired,
 * so nothing can still be writing to the site and Retry setup can run again.
 * A site still waiting in the queue is never touched — installs run one at a
 * time, and a long queue is not a dead job.
 *
 * Marked failed, like `ProvisionApplication::failed()` does, not deleted: the
 * user sees why and chooses between Retry setup and Delete.
 */
class StrandedProvisioning
{
    public function __construct(private ProvisioningBudget $budget, private ActivityLogger $activity) {}

    public function isStranded(Application $application): bool
    {
        if ($application->status !== ApplicationStatus::Provisioning || $application->provisioning_started_at === null) {
            return false;
        }

        $deadline = $this->budget->forSiteType($application->site_type) + ProvisionApplication::UNIQUE_LOCK_GRACE;

        return $application->provisioning_started_at->lt(now()->subSeconds($deadline));
    }

    /** Mark it failed if it is stranded. True when it was. */
    public function close(Application $application): bool
    {
        if (! $this->isStranded($application)) {
            return false;
        }

        $reference = (string) Str::uuid();

        // Guarded on the same status and start time, so a retry that began a
        // moment ago is not marked failed under it.
        $updated = Application::whereKey($application->id)
            ->where('status', ApplicationStatus::Provisioning->value)
            ->where('provisioning_started_at', $application->provisioning_started_at)
            ->update([
                'status' => ApplicationStatus::Failed->value,
                'failed_step' => 'worker',
                'reference' => $reference,
            ]);

        if ($updated !== 1) {
            return false;
        }

        Log::error('provisioning job never finished; marked failed', [
            'reference' => $reference,
            'application_id' => $application->id,
            'started_at' => $application->provisioning_started_at->toIso8601String(),
        ]);

        $application->refresh();

        $this->activity->log('application.provision_failed', $application, [
            'name' => $application->name,
            'step' => 'worker',
        ]);

        return true;
    }

    /** Every stranded site. Returns how many were closed. */
    public function closeAll(): int
    {
        return Application::query()
            ->where('status', ApplicationStatus::Provisioning->value)
            ->whereNotNull('provisioning_started_at')
            ->get()
            ->filter(fn (Application $application) => $this->close($application))
            ->count();
    }
}
