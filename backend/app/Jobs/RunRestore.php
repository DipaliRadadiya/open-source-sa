<?php

namespace App\Jobs;

use App\Enums\RestoreStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\Restore;
use App\Services\ActivityLogger;
use App\Services\Server\Restores\RestoreRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one restore off the queue.
 *
 * **Never retried.** A backup that fails can be re-run harmlessly; a restore
 * that fails has already changed the application, and a second automatic
 * attempt would start from that half-changed state rather than from the one
 * the safety backup describes. The next attempt has to be a person's decision.
 *
 * Unique per application, and held to completion rather than to pickup
 * (ShouldBeUniqueUntilProcessing releases at pickup) — two restores writing
 * the same site directory at once is the one thing worse than none.
 */
class RunRestore implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    /**
     * An hour. A restore downloads the archive, takes a full safety backup and
     * imports a database, so it is legitimately slower than the backup it came
     * from. `retry_after` on the connection must exceed this.
     */
    public int $timeout = 3600;

    public function __construct(public int $restoreId, public int $applicationId) {}

    public function uniqueId(): string
    {
        return 'restore-application-'.$this->applicationId;
    }

    public function handle(RestoreRunner $runner, ActivityLogger $activity): void
    {
        $restore = Restore::with(['backup.target.storageDestination', 'application.systemUser'])
            ->find($this->restoreId);

        if ($restore === null) {
            return;
        }

        if ($restore->backup === null) {
            // The source was pruned between queueing and running. Failing
            // loudly beats restoring whatever the next-nearest thing is.
            $restore->update([
                'status' => RestoreStatus::Failed,
                'reason' => 'missing_backup',
                'finished_at' => now(),
            ]);

            return;
        }

        $restore = $runner->run($restore);

        $activity->log(
            $restore->status === RestoreStatus::Succeeded ? 'backup.restored' : 'backup.restore_failed',
            $restore,
            [
                'application' => $restore->application->name,
                'reason' => $restore->reason ?? '',
            ],
        );
    }

    /**
     * The job died outright — timeout, OOM, worker killed. Without this the
     * row sits at `running` forever while the site may be mid-swap, which is
     * the state an operator most needs to be told about.
     */
    public function failed(?Throwable $e): void
    {
        Log::channel('server-ops')->error('restore job crashed', [
            'feature' => 'backup',
            'op' => 'restore',
            'restore' => $this->restoreId,
            'application' => $this->applicationId,
            'detail' => $e?->getMessage(),
        ]);

        Restore::query()
            ->whereKey($this->restoreId)
            ->whereIn('status', [RestoreStatus::Pending->value, RestoreStatus::Running->value])
            ->update([
                'status' => RestoreStatus::Failed->value,
                'reason' => 'crashed',
                'finished_at' => now(),
            ]);
    }
}
