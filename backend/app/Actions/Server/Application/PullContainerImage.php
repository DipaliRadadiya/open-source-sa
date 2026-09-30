<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;

/**
 * Pull this site's image again and recreate the container on it.
 *
 * Refused for a site that is not running, rather than quietly provisioning one
 * as a side effect: a pending site has no compose file to pull from, and a
 * disabled site is deliberately offline — bringing its container up because
 * somebody pressed Update would put a site back online that was taken down on
 * purpose.
 */
class PullContainerImage
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
    ) {}

    public function execute(Application $application): Application
    {
        abort_unless(
            $application->status === ApplicationStatus::Active && $application->disabled_at === null,
            422,
            __('errors/application.container_not_running'),
        );

        // Recorded BEFORE the pull, not after. A pull that fails, times out, or
        // takes the site down is the one somebody will be looking for in the log,
        // and an entry written only on success is an entry missing exactly then.
        $this->activityLogger->log('application.container_pulled', $application, [
            'name' => $application->name,
            'image' => $application->image,
            'registry_id' => $application->registry_id,
        ]);

        // Cleared first, so a row that failed last time does not keep showing
        // yesterday's reason beside today's success.
        $application->forceFill(['failed_step' => null, 'failed_reason' => null])->save();

        try {
            $this->containers->pull(
                $application,
                $this->provisioner->documentRoot($application),
            );
        } catch (ProvisioningFailedException $e) {
            // Recorded on the row, not only thrown. The provisioning JOB does this
            // for every other failure, and this endpoint is synchronous — so
            // without it the card beside the button shows a healthy site while the
            // pull that just failed is reported only by a toast that clears itself.
            //
            // Found on a real box: a rejected credential returned a bare 500
            // "Server Error" and left `failed_reason` null, which is exactly the
            // uninformative failure this whole feature exists to replace.
            $application->forceFill([
                'failed_step' => $e->step,
                'failed_reason' => $e->reason,
            ])->save();

            throw $e;
        }

        return $application->fresh(['systemUser', 'registry']);
    }
}
