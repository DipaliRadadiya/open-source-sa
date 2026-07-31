<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\GitDeployer;
use App\Services\Server\Applications\ProvisioningBudget;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Fetches the application's code. Same shape as provisioning: queued, one
 * attempt, explicit retry.
 *
 * A failed deploy leaves the site serving whatever it was serving before —
 * the clone/reset either completes or it doesn't, and the web server config is
 * untouched either way. That matters: a broken build must not take a working
 * site offline.
 */
class DeployApplication implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * One queued deploy per application.
     *
     * A webhook fires per push, and pushes arrive in bursts — ten commits to a
     * branch would otherwise queue ten identical deploys, each fetching the
     * same tip. Unique **until processing** rather than until finished, so a
     * push that lands while a deploy is running still queues one behind it:
     * that deploy started before the new commit existed, so dropping the second
     * would leave the newest code undeployed with nothing to say so.
     */
    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    /**
     * Clone plus build plus the steps around them — see
     * {@see ProvisioningBudget::forDeploy()}. The old flat 900 was exactly
     * `git_timeout` + `build_timeout`, leaving nothing for the chown and the
     * restart that follow it.
     */
    public int $timeout;

    public function __construct(public int $applicationId)
    {
        $this->timeout = app(ProvisioningBudget::class)->forDeploy();
    }

    public function handle(
        GitDeployer $deployer,
        ApplicationProvisioner $provisioner,
        ActivityLogger $activityLogger,
    ): void {
        $application = Application::with(['systemUser', 'gitAccount'])->find($this->applicationId);

        if ($application === null) {
            return;
        }

        $previousStatus = $application->status;

        $application->update(['status' => ApplicationStatus::Provisioning, 'failed_step' => null, 'reference' => null]);

        try {
            $result = $deployer->deploy($application, $provisioner->documentRoot($application));

            $application->update([
                'status' => ApplicationStatus::Active,
                'steps' => $result['steps'],
                'last_commit' => $result['commit'],
                'last_deployed_at' => now(),
            ]);

            $activityLogger->log('application.deployed', $application, [
                'name' => $application->name,
                'branch' => $application->branch,
            ], actor: null);
        } catch (ProvisioningFailedException $e) {
            $application->update([
                // Back to what it was: if the site was already live, a failed
                // redeploy has not changed that.
                'status' => $previousStatus === ApplicationStatus::Active
                    ? ApplicationStatus::Active
                    : ApplicationStatus::Failed,
                'failed_step' => $e->step,
                'reference' => $e->reference,
            ]);

            $activityLogger->log('application.deploy_failed', $application, [
                'name' => $application->name,
                'step' => $e->step,
            ], actor: null);
        }
    }

    public function failed(?Throwable $e): void
    {
        Application::whereKey($this->applicationId)
            ->where('status', ApplicationStatus::Provisioning->value)
            ->update(['status' => ApplicationStatus::Failed->value, 'failed_step' => 'worker']);
    }
}
