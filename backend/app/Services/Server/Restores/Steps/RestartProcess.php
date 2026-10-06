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
 * PHP sites need nothing — the web server picks up the new files on the next
 * request. Anything systemd supervises was stopped before the swap and is owed
 * a start, and failing to give it one leaves a site that is "restored" and
 * completely down.
 *
 * **Container sites are the same owing, through a different supervisor.**
 * `ProcessSupervisor` knows about systemd units, and a container site has none,
 * so gating only on `runs()` skipped this step entirely for them: the restore
 * stopped the containers, replaced the volume contents, swapped the compose
 * file in, and left the site returning 502. Measured on the Docker test box —
 * `docker ps` empty, the site 502, the restore recorded as succeeded. Exactly
 * the sentence above, for the one site type it did not cover.
 *
 * Starting happens here rather than in `RestoreVolumes` on purpose: this step
 * runs after `SwapFiles`, so the containers come up on the *restored* compose
 * file. Starting them earlier would bring them up on the old definition and
 * then swap it underneath them.
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
        return $this->isContainerSite($context) || $this->processes->runs($context->application);
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
        // A restore that failed after the process was stopped must still leave
        // the application running — the site was up when the user pressed the
        // button, and a failed restore that also takes it offline turns a
        // recoverable mistake into an outage.
        //
        // SwapFiles::cleanup carries the same guard, because a failure *in*
        // the swap means this step never ran and its cleanup never fires. The
        // flag makes running both harmless.
        if (! $failed || ! $context->processStopped) {
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
