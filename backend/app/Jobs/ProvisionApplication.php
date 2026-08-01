<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\Concerns\TracksActor;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ProvisioningBudget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Provisioning takes long enough that holding the HTTP request open would be
 * wrong, so it runs on the queue and the app's status is what the UI polls.
 *
 * `$tries = 1` on purpose. A queued job can run twice on a kill or a retry,
 * and blind retries of server mutations are how half-applied state happens.
 * The steps are idempotent so a *deliberate* re-run converges, but an
 * automatic one would just repeat a failure the user needs to see and fix —
 * they get an explicit retry action instead.
 */
class ProvisionApplication implements ShouldQueue
{
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    /**
     * Sized from the site type's own installer timeout rather than fixed —
     * see {@see ProvisioningBudget}. Set here because the constructor runs at
     * dispatch and the property travels with the serialized job.
     */
    public int $timeout;

    public function __construct(public int $applicationId, public ?int $actorId = null)
    {
        $this->timeout = app(ProvisioningBudget::class)->forApplication($applicationId);
    }

    public function handle(ApplicationProvisioner $provisioner, ActivityLogger $activityLogger): void
    {
        $application = Application::with('systemUser')->find($this->applicationId);

        // Deleted while queued — nothing to do, and nothing to complain about.
        if ($application === null) {
            return;
        }

        $application->update(['status' => ApplicationStatus::Provisioning, 'steps' => [], 'reference' => null]);

        try {
            $steps = $provisioner->provision($application);

            $application->update([
                'status' => ApplicationStatus::Active,
                'steps' => $steps,
                'reference' => null,
            ]);

            $activityLogger->log('application.provisioned', $application, [
                'name' => $application->name,
            ], actor: $this->actor());
        } catch (ProvisioningFailedException $e) {
            // The step that broke is useful to the user; the raw stderr is not,
            // and lives only in the server-ops log under this reference.
            $application->update([
                'status' => ApplicationStatus::Failed,
                'failed_step' => $e->step,
                'reference' => $e->reference,
            ]);

            $activityLogger->log('application.provision_failed', $application, [
                'name' => $application->name,
                'step' => $e->step,
            ], actor: $this->actor());
        }
    }

    /**
     * The job itself died (timeout, worker killed). Leave the record honest
     * rather than stuck at `provisioning` forever.
     */
    public function failed(?Throwable $e): void
    {
        Application::whereKey($this->applicationId)
            ->where('status', ApplicationStatus::Provisioning->value)
            ->update(['status' => ApplicationStatus::Failed->value, 'failed_step' => 'worker']);
    }
}
