<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\SyncRun;
use App\Services\Server\Sync\ServerSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the whole server into the panel, off the queue.
 *
 * Queued because a large box is not an HTTP request: the caller gets a run id and polls it.
 *
 * Not retried: a half-finished apply should be looked at before it is repeated (a retry
 * is safe, adoption is idempotent, but it should be a decision).
 *
 * Unique, because two syncs at once would race to adopt the same username.
 */
class RunServerSync implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    /**
     * Ceiling for the phases that walk every vhost on a large server; under the queue's
     * reservation window (see QueueTimeoutTest).
     *
     * A constant because `SyncRun` measures staleness against it.
     */
    public const TIMEOUT = 900;

    public int $timeout = self::TIMEOUT;

    public function __construct(public int $syncRunId) {}

    public function uniqueId(): string
    {
        return 'server-sync';
    }

    public function handle(ServerSync $sync): void
    {
        $run = SyncRun::find($this->syncRunId);

        if ($run === null || $run->status->finished()) {
            return;
        }

        $sync->run($run);
    }

    public function failed(?Throwable $e): void
    {
        // The job itself died — timeout, or the worker was killed. Without
        // this the run sits at `running` forever and the screen spins.
        $run = SyncRun::find($this->syncRunId);

        if ($run === null || $run->status->finished()) {
            return;
        }

        Log::channel('server-ops')->error('server sync job died', [
            'feature' => 'sync',
            'op' => 'job_failed',
            'sync_run' => $run->id,
            'detail' => $e?->getMessage(),
        ]);

        $run->forceFill(['status' => SyncStatus::Failed, 'finished_at' => now()])->save();
    }
}
