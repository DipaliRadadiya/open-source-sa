<?php

namespace App\Jobs;

use App\Enums\CloneStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Exceptions\Server\ServerOperationException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\SiteClone;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\CloneManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /**
     * Above the copy's own limit (server.clone.rsync_timeout, 900 s) plus the
     * database copy and setup around it. At 600 s a large site was killed in
     * the middle of the rsync the job itself allowed 900 s for (CL-B4).
     */
    public int $timeout = 1800;

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

            // A reason *code* and a reference, never the exception text: it
            // reached the screen as raw SQL with the database file's path
            // (CL-B2). The text stays in the log line above, under the same
            // reference.
            [$reason, $reference] = self::classify($e);

            Log::channel('server-ops')->error('clone failed: reference', [
                'clone' => $this->cloneId,
                'reference' => $reference,
                'detail' => $e->getMessage(),
            ]);

            $clone->update([
                'status' => CloneStatus::Failed,
                'reason' => $reason,
                'reference' => $reference,
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof ProvisioningFailedException => ['setup_failed', $e->reference],
            $e instanceof ServerOperationException => ['copy_failed', $e->reference],
            default => ['failed', (string) Str::uuid()],
        };
    }

    public function failed(?Throwable $e): void
    {
        Log::channel('server-ops')->error('clone job crashed', [
            'feature' => 'application',
            'op' => 'clone',
            'clone' => $this->cloneId,
            'detail' => $e?->getMessage(),
        ]);

        $clone = SiteClone::query()
            ->whereKey($this->cloneId)
            ->whereIn('status', [CloneStatus::Pending->value, CloneStatus::Running->value])
            ->first();

        if ($clone === null) {
            return;
        }

        try {
            app(CloneManager::class)->discardAbandoned($clone);
        } catch (Throwable $cleanupFailure) {
            Log::channel('server-ops')->warning('could not discard an abandoned clone', [
                'clone' => $this->cloneId,
                'detail' => $cleanupFailure->getMessage(),
            ]);
        }

        SiteClone::query()
            ->whereKey($this->cloneId)
            ->whereIn('status', [CloneStatus::Pending->value, CloneStatus::Running->value])
            ->update([
                'status' => CloneStatus::Failed->value,
                'reason' => 'crashed',
                'reference' => (string) Str::uuid(),
                'finished_at' => now(),
            ]);
    }
}
