<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;

/**
 * Save a container site's structured fields and make them true on the box.
 *
 * The second half is the point. These values exist only as inputs to the
 * compose file, so a save that writes the row and stops leaves the panel
 * showing a network the container is not on — until some unrelated deploy
 * happens to rewrite the file, at which point the change lands with nothing to
 * connect it to. That is the shape of "built but not wired", and it is why the
 * apply is here rather than left to the next caller.
 *
 * `compose up -d` RECREATES the container when the file changed, so this is
 * brief downtime for that one site. The UI says so before the save; it is not
 * something to discover from a graph.
 */
class UpdateContainerSettings
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Application $application, array $data): Application
    {
        $application->forceFill($data)->save();

        // Nothing on disk to rewrite for a site that was never provisioned, and
        // a disabled one is deliberately not running — bringing its container up
        // as a side effect of saving a form would put the site back online.
        $applies = $application->status === ApplicationStatus::Active
            && $application->disabled_at === null;

        $failure = null;

        if ($applies) {
            try {
                $this->containers->apply(
                    $application,
                    $this->provisioner->documentRoot($application),
                );
            } catch (ProvisioningFailedException $e) {
                // The row is already saved, deliberately: the settings the user
                // asked for ARE what this site is configured to run, and reverting
                // them would leave the compose file on disk disagreeing with the
                // panel. What failed is making them true right now.
                //
                // Recorded and rethrown, so the caller gets a named reason instead
                // of a 500. Reachable in practice since the registry became
                // editable here: changing it re-pulls, and a wrong credential fails
                // the apply — which used to answer "Server Error".
                $application->forceFill([
                    'failed_step' => $e->step,
                    'failed_reason' => $e->reason,
                ])->save();

                $failure = $e;
            }
        }

        $this->activityLogger->log('application.container_updated', $application, [
            'network' => $application->docker_network,
            'container_port' => $application->container_port,
            'memory_limit' => $application->memory_limit,
            'volume_mounts' => count((array) ($application->volume_mounts ?? [])),
            // Which credential, by id — the registry's name would be friendlier
            // and would also go stale the moment it is renamed. The log records
            // what was chosen, not what it was called at the time.
            'registry_id' => $application->registry_id,
            // Recorded, because a save that only wrote the row and a save that
            // recreated the container are different events and the log is where
            // somebody will look to tell them apart.
            'applied' => $applies,
            // Distinguishes "saved and running" from "saved, but the container did
            // not come back" — the second is the event somebody will be hunting.
            'apply_failed' => $failure?->reason ?? ($failure !== null ? $failure->step : null),
        ]);

        if ($failure !== null) {
            throw $failure;
        }

        return $application->fresh(['systemUser', 'registry']);
    }
}
