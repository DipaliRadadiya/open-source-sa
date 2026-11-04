<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ApplicationRuns;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\ServerOpsResult;

/**
 * Restart every container of a Docker application, in place.
 *
 * `compose restart`, not a recreate: same containers, same volumes, no pull —
 * a restart must never change what the application runs or what it keeps.
 *
 * Refused rather than attempted when the application is not in a state a
 * restart means anything for: one still being set up has no compose file, or
 * is mid-`up`, and a paused one is deliberately offline — its containers keep
 * running behind the unavailable page, and restarting them would only look like
 * it had been brought back.
 */
class RestartContainerApplication
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
        private ApplicationRuns $runs,
    ) {}

    public function execute(Application $application): ServerOpsResult
    {
        $name = ['name' => $application->name];

        abort_unless(
            $application->status === ApplicationStatus::Active,
            422,
            __('errors/application.container_restart_not_ready', $name),
        );

        abort_if(
            $application->disabled_at !== null,
            422,
            __('errors/application.container_restart_paused', $name),
        );

        // A Pull and redeploy is recreating these containers right now (D5): a
        // restart under it would act on the old ones or on half-made new ones.
        abort_if(
            $this->runs->redeployInFlight($application) !== null,
            409,
            __('errors/application.container_restart_redeploying', $name),
        );

        abort_unless(
            $this->containers->daemonAnswers(),
            422,
            __('errors/application.container_restart_docker_down', $name),
        );

        $result = $this->containers->restartAndConfirm(
            $application,
            $this->provisioner->documentRoot($application),
        );

        // Only a restart that happened is recorded as one. The other process
        // actions log either way, so a failed Restart reads "Restarted" in the
        // trail; the failure is in the server-ops log under its reference.
        if ($result->ok) {
            $this->activityLogger->log('application.process_restart', $application, $name);
        }

        return $result;
    }
}
