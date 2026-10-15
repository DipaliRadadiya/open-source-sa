<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\StagingManager;

/**
 * Update a site's staging copy from the live site (v7's sync-to-staging).
 */
class PullStaging
{
    public function __construct(
        private StagingManager $staging,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $production, string $mode): void
    {
        $this->staging->pull($production, $mode);

        $this->activityLogger->log('application.staging_pulled_'.$mode, $production, [
            'name' => $production->name,
        ]);
    }
}
