<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;

class DisableApplication
{
    public function __construct(
        private ApplicationProvisioner $provisioner,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $application): void
    {
        $this->provisioner->disable($application->load('systemUser'));

        $this->activityLogger->log('application.disabled', $application, [
            'name' => $application->name,
        ]);
    }
}
