<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Moves the restored copy into place, and the live one out of the way.
 *
 * Two renames within the same parent directory, so the window in which the
 * site does not exist is measured in milliseconds regardless of how large it
 * is — a copy would take minutes and serve a half-written site throughout.
 *
 * The previous directory is *moved*, never deleted. It stays as
 * `.rollback-<id>` so that "the restore worked but the site is wrong" is still
 * recoverable by hand, which is a situation no amount of testing prevents.
 *
 * An application with a process (Node, and anything else systemd supervises)
 * is stopped first: swapping the directory under a running process leaves it
 * holding deleted file handles and serving code that no longer exists on disk.
 */
class SwapFiles implements RestoreStep
{
    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
        private ProcessSupervisor $processes,
    ) {}

    public function key(): string
    {
        return 'swap_files';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        return $context->wantsFiles();
    }

    public function run(RestoreContext $context): void
    {
        $siteRoot = $this->provisioner->documentRoot($context->application);
        $staged = $context->stagingDirectory.'/'.basename($siteRoot);
        $rollback = dirname($siteRoot).'/.rollback-'.$context->restore->id;

        if (! is_dir($staged)) {
            throw new RuntimeException('the restored copy is missing');
        }

        if ($this->processes->runs($context->application)) {
            $this->processes->stop($context->application);
            $context->processStopped = true;
        }

        if (is_dir($siteRoot)) {
            $this->move($context, $siteRoot, $rollback, 'restore_rollback_aside');
            $context->rollbackPath = $rollback;
            $context->restore->update(['rollback_path' => $rollback]);
        }

        $this->move($context, $staged, $siteRoot, 'restore_swap');

        // The archive preserves the ownership it was created with, but a
        // restore onto a rebuilt server can land files owned by a uid that no
        // longer maps to this site's user — which shows up as a white screen
        // nobody connects to the restore.
        $user = $context->application->systemUser?->username;

        if ($user !== null) {
            $this->serverOps->run(
                ['chown', '-R', $user.':'.$user, $siteRoot],
                ['feature' => 'backup', 'op' => 'restore_chown', 'application' => $context->application->id],
                timeout: 600,
            );
        }
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        if (! $failed) {
            return;
        }

        // Put the site back. Only when the swap actually happened — if the
        // move out of the way is what failed, the live directory never moved.
        $siteRoot = $this->provisioner->documentRoot($context->application);

        if ($context->rollbackPath !== null && is_dir($context->rollbackPath) && ! is_dir($siteRoot)) {
            $this->move($context, $context->rollbackPath, $siteRoot, 'restore_rollback');
            $context->rollbackPath = null;
            $context->restore->update(['rollback_path' => null]);
        }

        // If the failure happened inside this step, RestartProcess never ran
        // and its cleanup will never fire — so the process this step stopped
        // would stay stopped. The flag keeps the two from starting it twice.
        if ($context->processStopped) {
            $this->processes->start($context->application);
            $context->processStopped = false;
        }
    }

    private function move(RestoreContext $context, string $from, string $to, string $op): void
    {
        $result = $this->serverOps->run(
            ['mv', $from, $to],
            ['feature' => 'backup', 'op' => $op, 'application' => $context->application->id],
            timeout: 600,
        );

        if ($result->failed()) {
            throw new RuntimeException("could not move {$from} to {$to}");
        }
    }
}
