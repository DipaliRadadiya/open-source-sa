<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\StagingManager;

class PushStaging
{
    public function __construct(
        private StagingManager $staging,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $production, string $mode): void
    {
        $this->staging->push($production, $mode);

        $this->activityLogger->log('application.staging_pushed', $production, [
            'name' => $production->name,
            'mode' => $mode,
        ]);
    }
}
