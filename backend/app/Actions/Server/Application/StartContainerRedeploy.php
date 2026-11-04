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
 * Ask for a Pull and redeploy of a container site (D5, 2026-11-04).
 *
 * It ran inside the HTTP request until now: 19 s for a small image, minutes
 * for a large recipe, and nothing was recorded while it ran — a page reloaded
 * mid-pull showed a site with nothing happening to it, and Background jobs
 * stayed empty. So the request now checks, records a `queued` run, and hands
 * the pull to the queue; the run row is what the app screen and Background
 * jobs read. The pull itself is `PullContainerImage`, unchanged.
 *
 * One at a time per site. A second redeploy, a restart, or a settings or
 * compose save while one runs would start its own `compose up` against the
 * one in the job.
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
        // Not running, or paused: refused now, with the reason, rather than
        // queued to fail a moment later where nobody is looking.
        $this->pull->assertPullable($application);

        // Held only for the check-and-write, so two clicks cannot both find no
        // redeploy running and both queue one.
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

            // Recorded BEFORE the pull, not after. A pull that fails, times out,
            // or takes the site down is the one somebody will be looking for in
            // the log, and an entry written only on success is an entry missing
            // exactly then.
            $this->activityLogger->log('application.container_pulled', $application, [
                'name' => $application->name,
                'image' => $application->image,
                'registry_id' => $application->registry_id,
            ]);
        } finally {
            $lock->release();
        }

        // After the lock is released: on the `sync` driver the job runs right
        // here, and it must not run holding a lock it never asked for.
        try {
            RedeployContainerApplication::dispatch($run->id, Auth::id())->onQueue('default');
        } catch (Throwable $e) {
            // The queue would not take it: close the row, or it reads
            // "Redeploying" and refuses the next try for an hour.
            $this->runs->fail($run, 'queue');

            throw $e;
        }

        return $run->refresh();
    }
}
