<?php

namespace App\Jobs;

use App\Exceptions\Addons\AddonException;
use App\Jobs\Concerns\TracksActor;
use App\Models\AddonRun;
use App\Services\ActivityLogger;
use App\Services\Addons\AddonInstaller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs, updates or removes an addon binary for Central and records the
 * outcome on the AddonRun it is polling. Never retried, like every install:
 * a failure is rolled back and reported, and repeating it unattended would
 * only repeat the failure.
 */
class RunAddonLifecycle implements ShouldQueue
{
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $runId, public ?int $actorId = null)
    {
        // Above the download's own limit, so that one fires first and the
        // rollback gets to run.
        $this->timeout = (int) config('server.addons.install_timeout') + 300;
    }

    public function handle(AddonInstaller $installer, ActivityLogger $log): void
    {
        $run = AddonRun::find($this->runId);

        if ($run === null || $run->status !== AddonRun::QUEUED) {
            return;
        }

        $run->update(['status' => AddonRun::RUNNING, 'started_at' => now()]);

        $addon = $installer->addon($run->addon);
        $version = (string) ($run->arguments['version'] ?? '');

        try {
            $answer = $run->command === AddonRun::INSTALL
                ? $installer->install($addon, $run->arguments, $run->id)
                : $installer->remove($addon, $run->id);

            $run->update(['status' => AddonRun::SUCCEEDED, 'http_status' => 200, 'result' => $answer, 'finished_at' => now()]);

            match ($answer['action']) {
                'installed' => $log->log('addon.installed', null, ['addon' => $addon->label(), 'version' => $version], actor: $this->actor()),
                'updated' => $log->log('addon.updated', null, ['addon' => $addon->label(), 'version' => $version], actor: $this->actor()),
                'removed' => $log->log('addon.removed', null, ['addon' => $addon->label()], actor: $this->actor()),
            };

            return;
        } catch (AddonException $e) {
            $run->update(['status' => AddonRun::FAILED, 'http_status' => $e->status(), 'result' => $e->body(), 'finished_at' => now()]);
        } catch (Throwable $e) {
            report($e);

            $run->update([
                'status' => AddonRun::FAILED,
                'http_status' => 500,
                'result' => ['code' => 'addon_run_failed', 'message' => __('errors/addons.run_failed')],
                'finished_at' => now(),
            ]);
        }

        if ($run->command === AddonRun::INSTALL) {
            $log->log('addon.install_failed', null, ['addon' => $addon->label(), 'version' => $version], actor: $this->actor());
        } else {
            $log->log('addon.remove_failed', null, ['addon' => $addon->label()], actor: $this->actor());
        }
    }

    /** The worker killed it: the run must not stay "running" and block the next one. */
    public function failed(?Throwable $e): void
    {
        AddonRun::whereKey($this->runId)
            ->whereIn('status', [AddonRun::QUEUED, AddonRun::RUNNING])
            ->get()
            ->each(fn (AddonRun $run) => $run->update([
                'status' => AddonRun::FAILED,
                'http_status' => 504,
                'result' => ['code' => 'addon_timed_out', 'message' => __('errors/addons.timed_out', ['addon' => app(AddonInstaller::class)->addon($run->addon)->label()])],
                'finished_at' => now(),
            ]));
    }
}
