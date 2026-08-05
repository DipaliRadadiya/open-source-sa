<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;

class EnableApplication
{
    public function __construct(
        private ApplicationProvisioner $provisioner,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $application): void
    {
        $this->provisioner->enable($application->load('systemUser'));

        $this->activityLogger->log('application.enabled', $application, [
            'name' => $application->name,
        ]);
    }
}
