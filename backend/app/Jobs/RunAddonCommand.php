<?php

namespace App\Jobs;

use App\Exceptions\Addons\AddonException;
use App\Models\AddonRun;
use App\Services\Addons\WpToolkit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one queued addon command and records what the addon answered on the
 * AddonRun Central is polling. Never retried: a plugin update that got half
 * way is not something to run a second time unattended.
 */
class RunAddonCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $runId)
    {
        // A little above the command's own limit, so the command's timeout is
        // the one that fires and the run is recorded as timed out.
        $this->timeout = (int) config('server.addons.async_timeout') + 60;
    }

    public function handle(WpToolkit $toolkit): void
    {
        $run = AddonRun::find($this->runId);

        if ($run === null || $run->status !== AddonRun::QUEUED) {
            return;
        }

        $run->update(['status' => AddonRun::RUNNING, 'started_at' => now()]);

        try {
            $answer = $toolkit->run($run->arguments, (int) config('server.addons.async_timeout'), ['run' => $run->id]);

            $run->update(['status' => AddonRun::SUCCEEDED, 'http_status' => 200, 'result' => $answer, 'finished_at' => now()]);
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
    }

    /** The worker killed it: the run must not stay "running" forever. */
    public function failed(?Throwable $e): void
    {
        AddonRun::whereKey($this->runId)->whereIn('status', [AddonRun::QUEUED, AddonRun::RUNNING])->update([
            'status' => AddonRun::FAILED,
            'http_status' => 504,
            'result' => ['code' => 'addon_timed_out', 'message' => __('errors/addons.timed_out', ['addon' => 'WP Toolkit'])],
            'finished_at' => now(),
        ]);
    }
}
