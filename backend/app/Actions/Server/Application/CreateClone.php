<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\CloneManager;

class CreateClone
{
    public function __construct(
        private CloneManager $cloner,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $source, string $domain): Application
    {
        $clone = $this->cloner->clone($source, $domain);

        $this->activityLogger->log('application.cloned', $source, [
            'name' => $source->name,
            'domain' => $domain,
        ]);

        return $clone;
    }
}
