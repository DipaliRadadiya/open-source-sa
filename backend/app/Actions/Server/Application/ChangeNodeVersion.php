<?php

namespace App\Actions\Server\Application;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\HttpReadinessCheck;
use App\Services\Server\Applications\ProcessSupervisor;
use Throwable;

/**
 * Move a site to another installed Node version.
 *
 * **No rebuild, on purpose.** The compiled parts of the one-click Node applications load
 * unchanged across Node versions (Node-API or per-version prebuilds), and `npm rebuild` fails
 * anyway because the panel's installs leave their `.bin` scripts 0644.
 *
 * So the switch is only the unit, which makes the way back safe: rewrite the unit, restart, and
 * ask the application for a page. If it does not answer, the previous unit is written back and
 * restarted; no application file was touched.
 *
 * A stopped or disabled site gets the new unit without a start (starting it would be an
 * unrequested side effect). A site with no process (static or client-rendered git site) only
 * changes the version its next build uses.
 */
class ChangeNodeVersion
{
    public function __construct(
        private ProcessSupervisor $processes,
        private ApplicationProvisioner $provisioner,
        private HttpReadinessCheck $readiness,
        private ActivityLogger $activity,
    ) {}

    public function execute(Application $application, string $target): void
    {
        $application->loadMissing('systemUser');

        $previous = (string) $application->node_version;

        if (! $this->processes->runs($application)) {
            $this->succeeded($application, $previous, $target);

            return;
        }

        $root = $this->provisioner->documentRoot($application);

        // Read before anything changes: whether to start it is the user's state.
        $running = $application->disabled_at === null && $this->processes->active($application);

        $application->node_version = $target;
        [$reason, $reference] = $this->switchTo($application, $root, $running);

        if ($reason === null) {
            $this->succeeded($application, $previous, $target);

            return;
        }

        $application->node_version = $previous;
        [$rollback, $rollbackReference] = $this->switchTo($application, $root, $running);

        if ($rollback !== null) {
            $reason = 'rollback_failed';
            $reference = $rollbackReference ?? $reference;
        }

        $application->forceFill([
            'node_version' => $previous,
            'node_version_target' => $target,
            'node_version_failed_reason' => $reason,
            'node_version_failed_reference' => $reference,
        ])->save();

        $this->activity->log('application.node_version_change_failed', $application, [
            'name' => $application->name,
            'from' => $previous,
            'to' => $target,
        ]);
    }

    /**
     * The unit for whatever `node_version` holds now, restarted and asked for
     * a page when the site was running.
     *
     * @return array{0: ?string, 1: ?string} [failure reason, reference] — both null on success
     */
    private function switchTo(Application $application, string $root, bool $running): array
    {
        try {
            $written = $this->processes->rewriteUnit($application, $root);
        } catch (ProvisioningFailedException $e) {
            // ensurePm2() puts the installer's message where a reference goes.
            return ['install_pm2', mb_substr($e->reference, 0, 64)];
        }

        if ($written->failed()) {
            return ['unit_write', $written->reference];
        }

        if (! $running) {
            return [null, null];
        }

        $restarted = $this->processes->restart($application);

        if ($restarted->failed()) {
            return ['did_not_start', $restarted->reference];
        }

        try {
            $this->readiness->verify($application);
        } catch (ProvisioningFailedException $e) {
            return ['did_not_start', $e->reference];
        } catch (Throwable) {
            return ['did_not_start', null];
        }

        return [null, null];
    }

    private function succeeded(Application $application, string $previous, string $target): void
    {
        $application->forceFill([
            'node_version' => $target,
            'node_version_target' => null,
            'node_version_failed_reason' => null,
            'node_version_failed_reference' => null,
        ])->save();

        $this->activity->log('application.node_version_changed', $application, [
            'name' => $application->name,
            'from' => $previous !== '' ? $previous : '—',
            'to' => $target,
        ]);
    }
}
