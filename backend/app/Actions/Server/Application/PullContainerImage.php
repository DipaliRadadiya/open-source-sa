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
 * Refused for a site that is not running, rather than quietly provisioning one
 * as a side effect: a pending site has no compose file to pull from, and a
 * disabled site is deliberately offline — bringing its container up because
 * somebody pressed Update would put a site back online that was taken down on
 * purpose.
 */
class PullContainerImage
{
    public function __construct(
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * The refusals, on their own so the request can give them before anything
     * is queued (D5). Asked again by `execute()` when the job runs: the site can
     * be paused or start provisioning in the meantime.
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
     * Run by the queued job (`RedeployContainerApplication`). The activity
     * entry is written by `StartContainerRedeploy` when the redeploy is asked
     * for — still before the pull, and with the person who asked as its actor,
     * which a queue worker does not have.
     */
    public function execute(Application $application): Application
    {
        $this->assertPullable($application);

        // Cleared first, so a row that failed last time does not keep showing
        // yesterday's reason beside today's success.
        $application->forceFill(['failed_step' => null, 'failed_reason' => null])->save();

        try {
            $this->containers->pull(
                $application,
                $this->provisioner->documentRoot($application),
            );
        } catch (ProvisioningFailedException $e) {
            // Recorded on the row, not only thrown — and on the application, not
            // only on the run: the failure card beside the button reads these, so
            // without them it shows a healthy site while the pull that just failed
            // is reported nowhere the person who pressed it is looking.
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
