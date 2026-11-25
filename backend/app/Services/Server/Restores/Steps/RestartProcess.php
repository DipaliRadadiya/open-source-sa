<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Restores\RestoreContext;
use RuntimeException;

/**
 * Starts the application again.
 *
 * PHP sites need nothing: the web server picks up the new files on the next request. Anything
 * systemd supervises was stopped before the swap and must be started, or the site stays down.
 *
 * Container sites need the same start through a different supervisor: `ProcessSupervisor` only
 * knows systemd units, so gating on `runs()` alone would skip them.
 *
 * Starting happens here, not in `RestoreVolumes`: this step runs after `SwapFiles`, so the
 * containers come up on the *restored* compose file.
 */
class RestartProcess implements RestoreStep
{
    public function __construct(
        private ProcessSupervisor $processes,
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
    ) {}

    public function key(): string
    {
        return 'restart_process';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        // A database on its own has no process of its own to restart.
        return $context->application !== null
            && ($this->isContainerSite($context) || $this->processes->runs($context->application));
    }

    public function run(RestoreContext $context): void
    {
        $result = $this->isContainerSite($context)
            ? $this->containers->start(
                $context->application,
                $this->provisioner->documentRoot($context->application),
            )
            : $this->processes->restart($context->application);

        if ($result->failed()) {
            throw new RuntimeException('the application could not be started again');
        }

        $context->processStopped = false;
    }

    private function isContainerSite(RestoreContext $context): bool
    {
        return $context->application->serving_profile === 'docker';
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // A failed restore must still leave the application running, not turn into an outage.
        // SwapFiles::cleanup has the same guard (a failure in the swap means this cleanup never
        // fires); the flag makes running both harmless.
        if (! $failed || ! $context->processStopped || $context->application === null) {
            return;
        }

        if ($this->isContainerSite($context)) {
            $this->containers->start(
                $context->application,
                $this->provisioner->documentRoot($context->application),
            );
        } else {
            $this->processes->start($context->application);
        }

        $context->processStopped = false;
    }
}
