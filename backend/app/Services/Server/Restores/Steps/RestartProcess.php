<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Restores\RestoreContext;
use RuntimeException;

/**
 * Starts the application again.
 *
 * PHP sites need nothing — the web server picks up the new files on the next
 * request. Anything systemd supervises was stopped before the swap and is owed
 * a start, and failing to give it one leaves a site that is "restored" and
 * completely down.
 */
class RestartProcess implements RestoreStep
{
    public function __construct(private ProcessSupervisor $processes) {}

    public function key(): string
    {
        return 'restart_process';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        return $this->processes->runs($context->application);
    }

    public function run(RestoreContext $context): void
    {
        $result = $this->processes->restart($context->application);

        if ($result->failed()) {
            throw new RuntimeException('the application could not be started again');
        }

        $context->processStopped = false;
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // A restore that failed after the process was stopped must still leave
        // the application running — the site was up when the user pressed the
        // button, and a failed restore that also takes it offline turns a
        // recoverable mistake into an outage.
        //
        // SwapFiles::cleanup carries the same guard, because a failure *in*
        // the swap means this step never ran and its cleanup never fires. The
        // flag makes running both harmless.
        if ($failed && $context->processStopped) {
            $this->processes->start($context->application);
            $context->processStopped = false;
        }
    }
}
