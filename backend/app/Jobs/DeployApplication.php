<?php

namespace App\Jobs;

use App\Actions\Server\Application\UpdateApplicationWebRoot;
use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\GitDeployer;
use App\Services\Server\Applications\ProvisioningBudget;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches the application's code. Same shape as provisioning: queued, one attempt, explicit retry.
 *
 * A failed deploy leaves the site serving what it served before: the web server config is never
 * touched, so a broken build cannot take a working site offline.
 */
class DeployApplication implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    /**
     * One queued deploy per application, so a burst of pushes does not queue identical deploys.
     *
     * Unique until processing, not until finished: a push landing during a running deploy still
     * queues one behind it, since that deploy started before the new commit existed.
     */
    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    /**
     * Clone plus build plus the steps around them, see {@see ProvisioningBudget::forDeploy()}.
     */
    public int $timeout;

    /**
     * The deployment row is opened by whoever queues the job, so the screen shows `queued` before a
     * worker picks it up. Null for older queued jobs; they still run.
     */
    public function __construct(
        public int $applicationId,
        public ?int $actorId = null,
        public ?int $deploymentId = null,
    ) {
        $this->timeout = app(ProvisioningBudget::class)->forDeploy();
    }

    public function handle(
        GitDeployer $deployer,
        ApplicationProvisioner $provisioner,
        ActivityLogger $activityLogger,
        DeploymentRecorder $recorder,
    ): void {
        $application = Application::with(['systemUser', 'gitAccount'])->find($this->applicationId);

        if ($application === null) {
            return;
        }

        $deployment = $this->deploymentId === null ? null : Deployment::find($this->deploymentId);

        if ($deployment !== null) {
            $recorder->resume($deployment);
        }

        $previousStatus = $application->status;

        // Clear `failed_reason` too, or an old failure shows through every later deploy.
        $application->update([
            'status' => ApplicationStatus::Provisioning,
            'failed_step' => null,
            'failed_reason' => null,
            'reference' => null,
        ]);

        try {
            // The code root, not the document root: the checkout always lands at `public_html`;
            // `web_root` only selects what is served inside it.
            $result = $deployer->deploy($application, $application->codePath());

            $application->update([
                'status' => ApplicationStatus::Active,
                'steps' => $result['steps'],
                'last_commit' => $result['commit'],
                'last_deployed_at' => now(),
            ]);

            $recorder->succeed($result['commit'] ?? null, $result['message'] ?? null, $result['author'] ?? null);

            $this->servePublicDirectory($application);

            $activityLogger->log('application.deployed', $application, [
                'name' => $application->name,
                'branch' => $application->branch,
            ], actor: $this->actor());
        } catch (ProvisioningFailedException $e) {
            $application->update([
                // Back to what it was if the site was live. Not after the first deploy:
                // provisioning marks the site active before its code arrives.
                'status' => $previousStatus === ApplicationStatus::Active && $deployment?->trigger !== DeploymentTrigger::Initial
                    ? ApplicationStatus::Active
                    : ApplicationStatus::Failed,
                'failed_step' => $e->step,
                'failed_reason' => $e->reason,
                'reference' => $e->reference,
            ]);

            $recorder->fail($e->step, $e->reference, $e->reason);

            $activityLogger->log('application.deploy_failed', $application, [
                'name' => $application->name,
                'step' => $e->step,
            ], actor: $this->actor());
        }
    }

    public function failed(?Throwable $e): void
    {
        Application::whereKey($this->applicationId)
            ->where('status', ApplicationStatus::Provisioning->value)
            ->update(['status' => ApplicationStatus::Failed->value, 'failed_step' => 'worker']);

        // A crash must still close the row, or the screen spins forever.
        if ($this->deploymentId !== null) {
            Deployment::whereKey($this->deploymentId)
                ->whereIn('status', [DeploymentStatus::Queued->value, DeploymentStatus::Running->value])
                ->update([
                    'status' => DeploymentStatus::Failed->value,
                    'failed_step' => 'worker',
                    'finished_at' => now(),
                ]);
        }
    }

    /**
     * Serve `public/` for a Laravel or Symfony repository.
     *
     * Web root `/` would serve `.env` and the source. Only while the web root is still the default;
     * recognised by `public/index.php` plus the framework console and no top-level `index.php`.
     * Never fails the deploy: the user can set the web root by hand.
     */
    private function servePublicDirectory(Application $application): void
    {
        if ($application->serving_profile !== 'php' || trim((string) $application->web_root, '/') !== '') {
            return;
        }

        $ops = app(ServerOps::class);
        $root = rtrim($application->codePath(), '/');
        $exists = fn (string $path) => $ops->run(['test', '-f', "{$root}/{$path}"], ['feature' => 'application', 'op' => 'detect_framework', 'application' => $application->id])->ok;

        if (! $exists('public/index.php') || $exists('index.php') || (! $exists('artisan') && ! $exists('bin/console'))) {
            return;
        }

        try {
            app(UpdateApplicationWebRoot::class)->execute($application, 'public');
        } catch (Throwable $e) {
            Log::channel('server-ops')->warning('could not move a framework site to public/', [
                'application' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
