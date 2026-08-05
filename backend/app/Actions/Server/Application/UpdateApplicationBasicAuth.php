<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\BasicAuthManager;

class UpdateApplicationBasicAuth
{
    public function __construct(
        private BasicAuthManager $basicAuth,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $application, bool $enabled, ?string $username, ?string $password): void
    {
        $application->load('systemUser');

        if ($enabled) {
            $this->basicAuth->protect($application, $username, $password);

            $this->activityLogger->log('application.basic_auth_enabled', $application, [
                'name' => $application->name,
            ]);

            return;
        }

        if (! $application->basic_auth_enabled) {
            return;
        }

        $this->basicAuth->unprotect($application);

        $this->activityLogger->log('application.basic_auth_disabled', $application, [
            'name' => $application->name,
        ]);
    }
}
