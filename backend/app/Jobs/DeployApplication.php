<?php

namespace App\Jobs;

use App\Actions\Server\Application\UpdateApplicationWebRoot;
use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
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
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

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

    /**
     * The row is opened by whoever queues the job, not here, so the screen can
     * show `queued` in the seconds before a worker picks it up. Null only for a
     * deploy queued by something that predates this — the job still runs.
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

        // `failed_reason` cleared with the rest of them. It was not, so a site
        // that once failed for a nameable reason carried that sentence on its
        // screen through every later deploy — including the successful ones.
        $application->update([
            'status' => ApplicationStatus::Provisioning,
            'failed_step' => null,
            'failed_reason' => null,
            'reference' => null,
        ]);

        try {
            // The code root, not the document root. A checkout always lands at
            // `public_html`; `web_root` selects what the web server serves
            // *inside* it. Passing the document root here moved the checkout
            // down with the web root, so a repository whose front controller
            // lives in `public/` had no working configuration at all — empty
            // web root and the served directory has no index, `/public` and
            // the application's own `public/` lands one level too deep.
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
                // Back to what it was: if the site was already live, a failed
                // redeploy has not changed that.
                'status' => $previousStatus === ApplicationStatus::Active
                    ? ApplicationStatus::Active
                    : ApplicationStatus::Failed,
                'failed_step' => $e->step,
                // Dropped on the floor until now: the provisioning job has
                // always persisted this, the deploy job never did, so every
                // classification the panel can make was thrown away on exactly
                // the path a user hits most — redeploying an existing site.
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

        // A crash still has to close the row. Left running, the screen shows a
        // spinner that never stops on a deploy that is not happening.
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
     * Serve `public/` for a Laravel or Symfony repository (bug #43).
     *
     * A Git PHP site starts on web root `/`, and these frameworks keep their
     * front controller in `public/` with `.env`, the source and `vendor/`
     * beside it — so `/` served the secrets (`/.env`) and no application.
     * Only while the web root is still the default: one the user chose is
     * theirs. Recognised by the front controller plus the framework's own
     * console, and no `index.php` at the top that `/` could be meant for.
     *
     * Never fails the deploy: the code is live, and the user can still set
     * the web root by hand.
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
