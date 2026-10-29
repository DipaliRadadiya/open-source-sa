<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Models\User;
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

    public function execute(Application $production, string $mode, ?User $actor = null, bool $safetyCopy = true): void
    {
        $this->staging->pull($production, $mode, $safetyCopy);

        // The actor passed on: this runs on the queue, where no one is signed in.
        $this->activityLogger->log('application.staging_pulled_'.$mode, $production, [
            'name' => $production->name,
            'safety_copy' => $safetyCopy,
        ], $actor);
    }
}
