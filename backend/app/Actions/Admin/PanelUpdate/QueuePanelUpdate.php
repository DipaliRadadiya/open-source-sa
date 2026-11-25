<?php

namespace App\Actions\Admin\PanelUpdate;

use App\Enums\PanelUpdateStatus;
use App\Models\PanelUpdate;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Panel\AvailableRelease;
use App\Services\Panel\InstalledPanelInfo;
use App\Services\Panel\PanelUpdateRunner;
use App\Services\Panel\UpdatePreflight;
use App\Services\Panel\UpdateScript;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class QueuePanelUpdate
{
    public function __construct(
        private InstalledPanelInfo $installed,
        private AvailableRelease $releases,
        private UpdatePreflight $preflight,
        private PanelUpdateRunner $runner,
        private ActivityLogger $activity,
    ) {}

    public function execute(User $actor, bool $dryRun = false): PanelUpdate
    {
        // The lock is taken before the row is read: two admins pressing together would otherwise
        // both pass the in-flight check and both start a checkout of the same tree.
        $lock = Cache::lock('panel-update', 10);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'version' => [__('panel_update.errors.in_progress')],
            ]);
        }

        try {
            // Ask the state file before believing the row. Only PanelUpdateRunner::reconcile()
            // advances a row, and only a visit to the update page calls it; the run itself is a
            // detached script that cannot write to the database (it restarts php-fpm midway).
            // An update that finished unwatched would leave the row "running" and refuse every
            // later update. Reconciling first costs one file read.
            foreach (PanelUpdate::whereIn('status', [
                PanelUpdateStatus::Pending->value,
                PanelUpdateStatus::Running->value,
            ])->get() as $unfinished) {
                if ($this->runner->reconcile($unfinished)->status->inFlight()) {
                    throw ValidationException::withMessages([
                        'version' => [__('panel_update.errors.in_progress')],
                    ]);
                }
            }

            $current = $this->installed->installed();
            $latest = $this->releases->latest();

            if (! $this->releases->isUpdate($current, $latest)) {
                throw ValidationException::withMessages([
                    'version' => [__('panel_update.errors.no_update')],
                ]);
            }

            // The version came from the release host, not the request, but it is interpolated
            // into a shell script: do not trust it on provenance alone.
            if (! UpdateScript::isValidVersion((string) $latest['version'])) {
                throw ValidationException::withMessages([
                    'version' => [__('panel_update.errors.bad_version')],
                ]);
            }

            // Preflight is a gate, not a warning: cheap to verify now, an outage to find midway.
            if (! $this->preflight->run()['ready']) {
                throw ValidationException::withMessages([
                    'version' => [__('panel_update.errors.preflight_failed')],
                ]);
            }

            $update = PanelUpdate::create([
                'user_id' => $actor->id,
                'status' => PanelUpdateStatus::Pending,
                // A dry run changes nothing, so it names no version it moved to.
                'dry_run' => $dryRun,
                'from_version' => $current['version'],
                'from_commit' => $current['commit_hash'],
                'to_version' => $dryRun ? null : $latest['version'],
                'started_at' => now(),
            ]);

            if (! $this->runner->start($update, (string) $latest['version'], $dryRun)) {
                $update->update([
                    'status' => PanelUpdateStatus::Failed,
                    'reason' => 'launch',
                    'finished_at' => now(),
                ]);

                $this->activity->log('panel_update.failed', $update, ['reason' => 'launch'], actor: $actor);

                return $update;
            }

            $this->activity->log('panel_update.started', $update, [
                'from_version' => (string) $current['version'],
                'to_version' => (string) $latest['version'],
            ], actor: $actor);

            return $update;
        } finally {
            $lock->release();
        }
    }
}
