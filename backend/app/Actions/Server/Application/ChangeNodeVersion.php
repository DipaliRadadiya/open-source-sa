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
 * Move a site to another installed Node version (junior re-test #12).
 *
 * There was no way to do this at all: `node_version` was set at creation and
 * a PUT carrying it was silently ignored, so a Node version any site used
 * could never be removed.
 *
 * **No rebuild, on purpose — measured, not assumed.** On the nginx test server
 * (2026-10-05) every compiled part of the four one-click Node applications
 * loads on Node 22, 24 and 26 unchanged: Uptime Kuma's sqlite, Node-RED's
 * bcrypt, NodeBB's cbor-extract and sharp, n8n's 108 `.node` files. They are
 * built against Node-API, which is stable across versions, or ship a prebuild
 * per version and pick at load time. n8n and Uptime Kuma were started on the
 * other versions and answered. And `npm rebuild` was not an option anyway:
 * the panel's installs leave their `.bin` scripts 0644, so it fails at once.
 *
 * So the switch is the unit and nothing else, and that is what makes the way
 * back safe: rewrite the unit, restart, and ask the application for a page.
 * If it does not answer, the previous unit is written back and restarted — no
 * file of the application was touched, so there is nothing else to undo.
 *
 * A site whose process is stopped, or that is disabled, gets the new unit
 * without a start: starting it would be a side effect nobody asked for. A
 * site with no process (a static or client-rendered git site) only changes
 * the version its next build uses.
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

        // Read before anything changes: whether to start it is the user's
        // state, not this action's.
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
