<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\GitDeployer;
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
class DeployApplication implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $applicationId) {}

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
