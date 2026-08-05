<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\StagingManager;

class CreateStaging
{
    public function __construct(
        private StagingManager $staging,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $production, string $domain): Application
    {
        $staging = $this->staging->create($production, $domain);

        $this->activityLogger->log('application.staging_created', $production, [
            'name' => $production->name,
            'domain' => $domain,
        ]);

        return $staging;
    }
}
