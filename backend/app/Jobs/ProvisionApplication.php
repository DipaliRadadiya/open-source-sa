<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentTrigger;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\ProvisioningBudget;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
class ProvisionApplication implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
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

    public function uniqueId(): string
    {
        return 'application-provision-'.$this->applicationId;
    }

    public function handle(ApplicationProvisioner $provisioner, ActivityLogger $activityLogger): void
    {
        // The one thing `steps` and the activity log can never show: proof the
        // worker actually picked this job up at all. Without this line, a job
        // that never ran (queue misconfigured, worker down) looks identical
        // in every other log to one still queued — this is what tells them apart.
        Log::info('provisioning job started', ['application_id' => $this->applicationId]);

        $application = Application::with('systemUser')->find($this->applicationId);

        // Deleted while queued — nothing to do, and nothing to complain about.
        if ($application === null) {
            return;
        }

        // Stamped on every run, including a retry — which is the whole reason
        // it exists. `created_at` only records when the row was made, so after
        // a retry an elapsed-time display built on it counts from the original
        // attempt and keeps climbing.
        $application->update([
            'status' => ApplicationStatus::Provisioning,
            'steps' => [],
            'reference' => null,
            'provisioning_started_at' => now(),
        ]);

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

            $this->deployInitialCode($application);
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
     * Fetch a git application's code once the site is serving.
     *
     * Provisioning deliberately builds the directory, vhost and unit without
     * any code, because the code has to arrive over the network and that is a
     * different kind of failure. But nothing then asked for it: a user who
     * pasted a repository URL got a provisioned site showing a placeholder,
     * with no indication that a second, manual step existed. "Create a site
     * from this repository" plainly includes fetching the repository.
     *
     * Recorded as a real deployment so it shows up in the history with its own
     * trigger, and dispatched rather than run inline so a slow clone cannot
     * time out the provisioning job.
     */
    private function deployInitialCode(Application $application): void
    {
        if ($application->site_type !== 'git') {
            return;
        }

        $deployment = app(DeploymentRecorder::class)->open(
            $application,
            DeploymentTrigger::Initial,
            $this->actorId,
        );

        DeployApplication::dispatch($application->id, $this->actorId, $deployment->id);
    }

    /**
     * The job itself died (timeout, worker killed, or an exception from a step
     * that isn't wrapped in ProvisioningFailedException — e.g. a marketplace
     * installer's download/extract). Leave the record honest rather than stuck
     * at `provisioning` forever.
     *
     * $e is the one piece of information that previously vanished entirely:
     * failed_step='worker' told the user *that* it died, never *why*. Logged
     * here because it's the only place this exception is ever seen at all.
     */
    public function failed(?Throwable $e): void
    {
        $reference = (string) Str::uuid();

        Log::error('provisioning job died at the worker level', [
            'reference' => $reference,
            'application_id' => $this->applicationId,
            'exception' => $e ? $e::class.': '.$e->getMessage() : null,
        ]);

        $updated = Application::whereKey($this->applicationId)
            ->where('status', ApplicationStatus::Provisioning->value)
            ->update([
                'status' => ApplicationStatus::Failed->value,
                'failed_step' => 'worker',
                'reference' => $reference,
            ]);

        if ($updated === 1) {
            $application = Application::find($this->applicationId);
            app(ActivityLogger::class)->log('application.provision_failed', $application, [
                'name' => $application?->name,
                'step' => 'worker',
            ], actor: $this->actor());
        }
    }
}
