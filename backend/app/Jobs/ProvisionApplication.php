<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentTrigger;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Models\Application;
use App\Models\ApplicationRun;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ApplicationRuns;
use App\Services\Server\Applications\DeployQueue;
use App\Services\Server\Applications\ProvisioningBudget;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provisioning runs on the queue; the app's status is what the UI polls.
 *
 * `$tries = 1` on purpose: blind retries of server mutations leave half-applied state. The
 * steps are idempotent, so a deliberate re-run converges; the user gets an explicit retry action.
 */
class ProvisionApplication implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    /**
     * Sized from the site type's installer timeout, see {@see ProvisioningBudget}. Set in the
     * constructor so it travels with the serialized job.
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

    /**
     * Queue it, or record at once that it could not be queued.
     *
     * Not named `queue()`: Laravel's dispatcher calls a job's own `queue()` method to push it.
     *
     * The site row is saved before this runs. If the queue refuses the job, the site is marked
     * failed (not left `pending` forever) so Retry setup works once the queue is back.
     */
    public static function queueOrFail(int $applicationId, ?int $actorId = null): void
    {
        try {
            static::dispatch($applicationId, $actorId);
        } catch (Throwable $e) {
            $job = new static($applicationId, $actorId);

            // The unique lock is taken before the push; release it or the next Retry setup is
            // dropped as a duplicate.
            try {
                (new UniqueLock(app(Cache::class)))->release($job);
            } catch (Throwable) {
                // The lock store is down too; the lock expires on its own.
            }

            $job->failed($e);
        }
    }

    public function handle(ApplicationProvisioner $provisioner, ActivityLogger $activityLogger): void
    {
        // Proof the worker picked the job up: otherwise a job that never ran looks the same in
        // every other log as one still queued.
        Log::info('provisioning job started', ['application_id' => $this->applicationId]);

        $application = Application::with('systemUser')->find($this->applicationId);

        // Deleted while queued: nothing to do.
        if ($application === null) {
            return;
        }

        // Stamped on every run, retries included: `created_at` would make elapsed time count
        // from the original attempt.
        $application->update([
            'status' => ApplicationStatus::Provisioning,
            'steps' => [],
            'reference' => null,
            'provisioning_started_at' => now(),
        ]);

        // What Background jobs lists: the row opened at queue time, or a new one for older jobs.
        // Resolved here, not injected, because callers invoke handle() with two services.
        $runs = app(ApplicationRuns::class);
        $run = $runs->startInstall($application, $this->actorId);

        try {
            $steps = $provisioner->provision($application);

            $application->update([
                'status' => ApplicationStatus::Active,
                'steps' => $steps,
                'reference' => null,
                // The admin password has been handed to the installer; kept on failure for Retry.
                'install_secrets' => null,
            ]);

            $runs->succeed($run);

            $activityLogger->log('application.provisioned', $application, [
                'name' => $application->name,
            ], actor: $this->actor());

            $this->deployInitialCode($application);
        } catch (ProvisioningFailedException $e) {
            // The failed step is shown to the user; the raw stderr stays in the server-ops log
            // under this reference.
            $application->update([
                'status' => ApplicationStatus::Failed,
                'failed_step' => $e->step,
                // Null for most failures; set only where the exit status names the cause
                // (chiefly an OOM kill, whose log entry is empty).
                'failed_reason' => $e->reason,
                'reference' => $e->reference,
            ]);

            $runs->fail($run, $e->step, $e->reason, $e->reference);

            $activityLogger->log('application.provision_failed', $application, [
                'name' => $application->name,
                'step' => $e->step,
            ], actor: $this->actor());
        }
    }

    /**
     * Fetch a git application's code once the site is serving.
     *
     * Provisioning builds the directory, vhost and unit without code (fetching it is a network
     * failure mode of its own), so the clone is queued here as a real deployment with its own
     * trigger. Dispatched, not run inline, so a slow clone cannot time out provisioning.
     */
    private function deployInitialCode(Application $application): void
    {
        if ($application->site_type !== 'git') {
            return;
        }

        app(DeployQueue::class)->queue($application, DeploymentTrigger::Initial, $this->actorId);
    }

    /**
     * The job itself died (timeout, worker killed, or an exception not wrapped in
     * ProvisioningFailedException). Mark the record failed rather than stuck at `provisioning`.
     *
     * $e is logged here: it is the only place this exception is ever seen.
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
            // Pending too: a job that never started leaves the site there.
            ->whereIn('status', [ApplicationStatus::Pending->value, ApplicationStatus::Provisioning->value])
            ->update([
                'status' => ApplicationStatus::Failed->value,
                'failed_step' => 'worker',
                'reference' => $reference,
            ]);

        if ($updated === 1) {
            app(ApplicationRuns::class)->failOpen($this->applicationId, ApplicationRun::INSTALL, 'worker', $reference);

            $application = Application::find($this->applicationId);
            app(ActivityLogger::class)->log('application.provision_failed', $application, [
                'name' => $application?->name,
                'step' => 'worker',
            ], actor: $this->actor());
        }
    }
}
