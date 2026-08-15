<?php

namespace App\Jobs;

use App\Enums\CloneStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\SiteClone;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\CloneManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one site clone off the queue.
 *
 * Not retried. A clone that fails mid-way has already created an application
 * record and possibly touched the filesystem — a transparent retry would start
 * from a half-changed state rather than from scratch. The next attempt has to
 * be a deliberate decision.
 *
 * Unique per source application: two clones of the same site at once would
 * both allocate the same system user and write to the same rsync destination.
 */
class RunClone implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    /** 10 minutes — rsync is the slow part for a large site. */
    public int $timeout = 600;

    public function __construct(
        public int $cloneId,
        public int $sourceApplicationId,
    ) {}

    public function uniqueId(): string
    {
        // Unique per source application: two clones of the same site at once would
        // both allocate the same system user and write to the same rsync destination.
        return 'clone-source-'.$this->sourceApplicationId;
    }

    public function handle(CloneManager $cloner, ActivityLogger $activity): void
    {
        $clone = SiteClone::with(['sourceApplication.systemUser', 'user'])->find($this->cloneId);

        if ($clone === null) {
            return;
        }

        $clone->update([
            'status' => CloneStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $target = $cloner->execute($clone);

            $clone->update([
                'status' => CloneStatus::Completed,
                'target_application_id' => $target->id,
                'finished_at' => now(),
            ]);

            // Logged here rather than in the controller: the 202 only means
            // the clone was accepted, and an activity entry written then would
            // claim a site exists before anything had been copied. The actor
            // is passed explicitly because a queued job has no authenticated
            // user to fall back on.
            $activity->log('application.cloned', $clone->sourceApplication, [
                'name' => $clone->sourceApplication?->name,
                'domain' => $clone->domain,
            ], $clone->user);
        } catch (Throwable $e) {
            Log::channel('server-ops')->error('clone failed', [
                'feature' => 'application',
                'op' => 'clone',
                'clone' => $this->cloneId,
                'source' => $clone->source_application_id,
                'reason' => $clone->reason,
                'reference' => $clone->reference,
                'detail' => $e->getMessage(),
            ]);

            $clone->update([
                'status' => CloneStatus::Failed,
                'reason' => $clone->reason ?: ($e instanceof Throwable ? substr($e->getMessage(), 0, 255) : 'failed'),
                'finished_at' => now(),
            ]);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::channel('server-ops')->error('clone job crashed', [
            'feature' => 'application',
            'op' => 'clone',
            'clone' => $this->cloneId,
            'detail' => $e?->getMessage(),
        ]);

        SiteClone::query()
            ->whereKey($this->cloneId)
            ->whereIn('status', [CloneStatus::Pending->value, CloneStatus::Running->value])
            ->update([
                'status' => CloneStatus::Failed->value,
                'reason' => 'crashed',
                'finished_at' => now(),
            ]);
    }
}
