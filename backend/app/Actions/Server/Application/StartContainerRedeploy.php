<?php

namespace App\Actions\Server\Application;

use App\Jobs\RedeployContainerApplication;
use App\Models\Application;
use App\Models\ApplicationRun;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationRuns;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ask for a Pull and redeploy of a container site.
 *
 * The request only checks and records a `queued` run, then hands the pull
 * (`PullContainerImage`) to the queue; the run row is what the app screen and
 * Background jobs read.
 *
 * One at a time per site: a second redeploy, a restart, or a settings or compose
 * save mid-run would start its own `compose up` against the one in the job.
 */
class StartContainerRedeploy
{
    public function __construct(
        private PullContainerImage $pull,
        private ApplicationRuns $runs,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $application): ApplicationRun
    {
        // Not running, or paused: refuse now with the reason, not fail later in the queue.
        $this->pull->assertPullable($application);

        // Held only for the check-and-write, so two clicks cannot both queue one.
        $lock = Cache::lock('container-redeploy:'.$application->id, 10);

        abort_unless($lock->get(), 409, __('errors/application.container_redeploy_running', ['name' => $application->name]));

        try {
            $this->runs->closeStranded($application);

            abort_if(
                $this->runs->redeployInFlight($application) !== null,
                409,
                __('errors/application.container_redeploy_running', ['name' => $application->name]),
            );

            $run = $this->runs->open($application, ApplicationRun::CONTAINER_REDEPLOY, Auth::id());

            // Logged BEFORE the pull: a pull that fails or takes the site down is the one
            // that must be in the log.
            $this->activityLogger->log('application.container_pulled', $application, [
                'name' => $application->name,
                'image' => $application->image,
                'registry_id' => $application->registry_id,
            ]);
        } finally {
            $lock->release();
        }

        // After the lock is released: the `sync` driver runs the job right here.
        try {
            RedeployContainerApplication::dispatch($run->id, Auth::id())->onQueue('default');
        } catch (Throwable $e) {
            // Queue refused it: close the row, or it reads "Redeploying" and blocks retries.
            $this->runs->fail($run, 'queue');

            throw $e;
        }

        return $run->refresh();
    }
}
