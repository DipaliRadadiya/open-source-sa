<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\ServerOpsResult;

/**
 * Start, stop or restart an application's own process.
 *
 * One verb per call, chosen by the caller — the same shape as the Services
 * screen's action, because it is the same job: someone is asking systemd to do
 * something to one unit, and wants to know whether it worked.
 */
class RunApplicationProcess
{
    /** @var array<int, string> */
    public const ACTIONS = ['start', 'stop', 'restart'];

    public function __construct(
        private ProcessSupervisor $supervisor,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $application, string $action): ServerOpsResult
    {
        $result = match ($action) {
            'start' => $this->supervisor->start($application),
            'stop' => $this->supervisor->stop($application),
            default => $this->supervisor->restart($application),
        };

        $this->activityLogger->log('application.process_'.$action, $application, [
            'name' => $application->name,
        ]);

        return $result;
    }
}
