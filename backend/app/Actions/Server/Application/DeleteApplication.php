<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;

/**
 * Removes the record only. Nothing exists on disk in P1; once provisioning
 * lands this grows a "remove the site's files and vhost too" step, which is
 * destructive and will need its own confirmation.
 */
class DeleteApplication
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(Application $application): void
    {
        $this->activityLogger->log('application.deleted', $application, [
            'name' => $application->name,
            'site_type' => $application->site_type,
        ]);

        $application->delete();
    }
}
