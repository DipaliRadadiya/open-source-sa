<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
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

        if ($applies) {
            $this->containers->apply(
                $application,
                $this->provisioner->documentRoot($application),
            );
        }

        $this->activityLogger->log('application.container_updated', $application, [
            'network' => $application->docker_network,
            'container_port' => $application->container_port,
            'memory_limit' => $application->memory_limit,
            // Recorded, because a save that only wrote the row and a save that
            // recreated the container are different events and the log is where
            // somebody will look to tell them apart.
            'applied' => $applies,
        ]);

        return $application->fresh(['systemUser']);
    }
}
