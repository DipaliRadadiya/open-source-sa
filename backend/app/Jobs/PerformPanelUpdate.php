<?php

namespace App\Jobs;

use App\Enums\PanelUpdateStatus;
use App\Exceptions\Admin\PanelUpdate\PanelUpdateHelperFailedException;
use App\Jobs\Concerns\TracksActor;
use App\Models\PanelUpdate;
use App\Services\ActivityLogger;
use App\Services\Panel\PanelUpdateRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Worker stub for a panel update.
 *
 * The privileged helper that actually switches releases (running the new
 * installer, rotating to the new tree, restarting services) is not part of
 * this change. Until it lands, this job is intentionally a no-op:
 *
 *  - It transitions the row from `pending` to `running` so the screen can
 *    show progress.
 *  - It then closes the row as `failed` with `reason=unsupported` and a
 *    reference id the user can quote to support.
 *  - It MUST NOT invoke source-control, package, process, shell, or service-management commands.
 *
 * The reason code `unsupported` is the one the lang file translates into a
 * real sentence — every other reason is reserved for the helper that does
 * the work. Today every panel update ends here, with the same message, and
 * the screen says so honestly: "panel update not supported yet" rather than
 * "panel update succeeded".
 *
 * Unique-per-update so a re-dispatch (test, recovery) cannot run twice
 * against the same row.
 */
class PerformPanelUpdate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $panelUpdateId,
        public ?int $actorId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'panel-update-'.$this->panelUpdateId;
    }

    public function handle(ActivityLogger $activityLogger, PanelUpdateRunner $runner): void
    {
        $update = PanelUpdate::find($this->panelUpdateId);

        if ($update === null) {
            // The row is the contract: without it the screen has nothing to
            // render and the activity log has no subject. Dispatched-by-id
            // rather than by model exactly so a missing row is a graceful
            // no-op, not a crash — same rule the deploy job follows.
            return;
        }

        // A row that is already terminal (a worker crash landed it here) is
        // not ours to re-open. Leaving the row alone means the screen sees the
        // truth of what happened, not a re-run that didn't happen.
        if (! $update->status->inFlight()) {
            return;
        }

        $update->update([
            'status' => PanelUpdateStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $result = $runner->run($update);

            $update->update([
                'status' => PanelUpdateStatus::Succeeded,
                'to_version' => $result['version'],
                'to_commit' => $result['commit'],
                'finished_at' => now(),
            ]);

            $activityLogger->log('panel_update.succeeded', $update, actor: $this->actor());
        } catch (PanelUpdateHelperFailedException $exception) {
            $update->update([
                'status' => PanelUpdateStatus::Failed,
                'reason' => 'helper',
                'reference' => $exception->reference,
                'finished_at' => now(),
            ]);

            $activityLogger->log('panel_update.failed', $update, [
                'reason' => 'helper',
                'reference' => $exception->reference,
            ], actor: $this->actor());
        }
    }

    /**
     * The job died outright — timeout, OOM, worker killed. Without this the
     * row sits at `running` forever and the screen spins on something that
     * stopped running.
     */
    public function failed(?Throwable $e): void
    {
        $reference = (string) Str::uuid();

        Log::error('Panel update worker crashed.', [
            'reference' => $reference,
            'panel_update' => $this->panelUpdateId,
            'exception' => $e?->getMessage(),
        ]);

        PanelUpdate::whereKey($this->panelUpdateId)
            ->whereIn('status', [
                PanelUpdateStatus::Pending->value,
                PanelUpdateStatus::Running->value,
            ])
            ->update([
                'status' => PanelUpdateStatus::Failed->value,
                'reason' => 'worker',
                'reference' => $reference,
                'finished_at' => now(),
            ]);
    }
}