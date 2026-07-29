<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\Server\Applications\ApplicationProvisioner;

/**
 * Stop serving a site: remove its config and reload.
 *
 * Deleting the user's files is opt-in. Removing a panel record should not
 * silently destroy the code someone uploaded — that is a different, much
 * larger decision than "take this off the panel".
 */
class DeprovisionApplication
{
    public function __construct(private ApplicationProvisioner $provisioner) {}

    public function execute(Application $application, bool $removeFiles = false): void
    {
        // Never provisioned — there is no config to remove and no reload to do.
        if ($application->status->value === 'pending') {
            return;
        }

        $this->provisioner->deprovision($application->load('systemUser'), $removeFiles);
    }
}
