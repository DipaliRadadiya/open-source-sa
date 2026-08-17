<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;

/**
 * Removes the record only.
 *
 * Everything on the server — vhost, systemd unit, PHP-FPM pool, and the files
 * when the caller asked for them — is `DeprovisionApplication`'s job, and the
 * controller runs it first. Splitting them is deliberate: taking a site off
 * the panel and destroying someone's code are different decisions.
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
