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

        // One sentence per mode (ST-B4): the mode was a raw code inside the
        // sentence — "Pushed staging to my-blog (full)" — in every language.
        // `staging_pushed` stays for rows written before.
        $this->activityLogger->log('application.staging_pushed_'.$mode, $production, [
            'name' => $production->name,
        ]);
    }
}
