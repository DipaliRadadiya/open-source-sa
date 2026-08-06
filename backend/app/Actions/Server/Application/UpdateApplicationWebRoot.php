<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\WebRootManager;

class UpdateApplicationWebRoot
{
    public function __construct(
        private WebRootManager $webRoot,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $application, ?string $webRoot): void
    {
        $previous = $application->web_root ?: '/';

        $this->webRoot->apply($application, $webRoot);

        // Logged only when the value actually moved: the manager treats an
        // unchanged web root as a no-op, and an activity entry for a change
        // that did not happen is noise in the one place that is supposed to
        // be a record of what did.
        if (($application->web_root ?: '/') === $previous) {
            return;
        }

        $this->activityLogger->log('application.web_root_changed', $application, [
            'name' => $application->name,
            'web_root' => $application->web_root ?: '/',
        ]);
    }
}
