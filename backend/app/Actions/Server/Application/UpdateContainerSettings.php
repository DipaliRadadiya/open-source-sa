<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Docker\OriginalNetwork;

/**
 * Save a container site's structured fields and make them true on the box.
 *
 * The values only feed the compose file, so saving the row alone would leave the panel showing
 * a network the container is not on. `compose up -d` RECREATES the container when the file
 * changed: brief downtime for that one site (the UI warns before the save).
 */
class UpdateContainerSettings
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
        private OriginalNetwork $originalNetwork,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $removePreviousNetwork  on a network change, remove the network
     *                                       the site was created on once the move is up
     *                                       (the user's choice; default true = as before).
     */
    public function execute(Application $application, array $data, bool $removePreviousNetwork = true): Application
    {
        $application->forceFill($data)->save();
        $networkChanged = $application->wasChanged('docker_network');

        // Nothing to rewrite for a never-provisioned site; a disabled one must not be brought
        // back online by saving a form.
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
                // The row stays saved on purpose (reverting would leave the compose file
                // disagreeing with the panel); only applying failed. Recorded and rethrown so
                // the caller gets a named reason instead of a 500.
                $application->forceFill([
                    'failed_step' => $e->step,
                    'failed_reason' => $e->reason,
                ])->save();

                $failure = $e;
            }

            // The old network is unused now. Only after a successful apply: until then the
            // container may still be on it. Never fatal, see release().
            if ($failure === null && $networkChanged && $removePreviousNetwork) {
                $this->originalNetwork->release($application);
            }
        }

        $this->activityLogger->log('application.container_updated', $application, [
            // The renderer substitutes only from these properties (`ActivityLogResource`), so
            // `:name` needs a `name` here.
            'name' => $application->name,
            'network' => $application->docker_network,
            'container_port' => $application->container_port,
            'memory_limit' => $application->memory_limit,
            'cpu_limit' => $application->cpu_limit,
            'volume_mounts' => count((array) ($application->volume_mounts ?? [])),
            // By id: a registry name would go stale when renamed.
            'registry_id' => $application->registry_id,
            // Tells a row-only save from one that recreated the container.
            'applied' => $applies,
            // "Saved and running" vs "saved, but the container did not come back".
            'apply_failed' => $failure?->reason ?? ($failure !== null ? $failure->step : null),
        ]);

        if ($failure !== null) {
            throw $failure;
        }

        return $application->fresh(['systemUser', 'registry']);
    }
}
