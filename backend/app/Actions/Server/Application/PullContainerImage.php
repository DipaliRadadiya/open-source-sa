<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;

/**
 * Pull this site's image again and recreate the container on it.
 *
 * Refused for a site that is not running: a pending site has no compose file to pull from, and a
 * disabled site is deliberately offline and must not be brought back up by an update.
 */
class PullContainerImage
{
    public function __construct(
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * The refusals, on their own so the request can give them before anything is queued. Asked
     * again by `execute()` when the job runs: the site can change state in the meantime.
     */
    public function assertPullable(Application $application): void
    {
        abort_unless(
            $application->status === ApplicationStatus::Active && $application->disabled_at === null,
            422,
            __('errors/application.container_not_running'),
        );
    }

    /**
     * Run by the queued job (`RedeployContainerApplication`). The activity entry is written by
     * `StartContainerRedeploy` when the redeploy is asked for, because a queue worker has no actor.
     */
    public function execute(Application $application): Application
    {
        $this->assertPullable($application);

        // Cleared first, so an earlier failure's reason does not show beside a success.
        $application->forceFill(['failed_step' => null, 'failed_reason' => null])->save();

        try {
            $this->containers->pull(
                $application,
                $this->provisioner->documentRoot($application),
            );
        } catch (ProvisioningFailedException $e) {
            // Recorded on the application, not only thrown: the failure card beside the button
            // reads these, so without them a failed pull shows a healthy site.
            $application->forceFill([
                'failed_step' => $e->step,
                'failed_reason' => $e->reason,
            ])->save();

            throw $e;
        }

        return $application->fresh(['systemUser', 'registry']);
    }
}
