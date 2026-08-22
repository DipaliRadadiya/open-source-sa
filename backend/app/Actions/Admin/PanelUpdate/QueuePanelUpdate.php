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
        // One at a time, and the lock is taken before the row is read. Two
        // admins pressing the button together would otherwise both pass the
        // in-flight check and both start a checkout of the same tree.
        $lock = Cache::lock('panel-update', 10);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'version' => [__('panel_update.errors.in_progress')],
            ]);
        }

        try {
            // Ask the state file before believing the row.
            //
            // A row is only advanced by PanelUpdateRunner::reconcile(), and the
            // only thing that calls it is somebody looking at the update page.
            // The run itself is a detached script that cannot write to the
            // database — it restarts php-fpm partway through, which is the
            // whole reason progress lives in a file.
            //
            // So an update that finished while nobody was watching — the tab
            // closed, the browser reloaded into a restarting panel, the run
            // outlived the session — leaves a row saying "running" forever,
            // and this check then refuses every future update. Permanently.
            // On the test server: row #7 said `pending` while its state file
            // had said `succeeded` for several minutes, and the panel answered
            // "An update is already running" to every press.
            //
            // Reconciling first costs one file read and makes the check ask
            // what actually happened rather than what was last written down.
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

            if (! $this->releases->isNewer($current['version'], $latest['version'])) {
                throw ValidationException::withMessages([
                    'version' => [__('panel_update.errors.no_update')],
                ]);
            }

            // Belt and braces: the version came from the release host, not the
            // request, but it is interpolated into a shell script and that is
            // not a place to extend trust on the basis of provenance.
            if (! UpdateScript::isValidVersion((string) $latest['version'])) {
                throw ValidationException::withMessages([
                    'version' => [__('panel_update.errors.bad_version')],
                ]);
            }

            // Preflight is a gate, not a warning. Everything it checks is
            // cheap to verify now and an outage to discover halfway through.
            if (! $this->preflight->run()['ready']) {
                throw ValidationException::withMessages([
                    'version' => [__('panel_update.errors.preflight_failed')],
                ]);
            }

            $update = PanelUpdate::create([
                'user_id' => $actor->id,
                'status' => PanelUpdateStatus::Pending,
                'from_version' => $current['version'],
                'from_commit' => $current['commit_hash'],
                'to_version' => $latest['version'],
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
