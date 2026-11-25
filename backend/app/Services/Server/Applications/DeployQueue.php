<?php

namespace App\Services\Server\Applications;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Queue a deploy, or join the one already waiting.
 *
 * `DeployApplication` is unique until processing: while one deploy for a site waits, Laravel
 * silently drops further dispatches. A caller that opened a `queued` Deployment row before
 * dispatching would leave a row no job ever picks up.
 *
 * So the lock is taken here first, with the same key and lifetime as Laravel's own check, and
 * only the caller that gets it opens a row and queues the job. Any other caller is handed the
 * deploy already waiting, which fetches the branch tip when it runs.
 */
class DeployQueue
{
    public function __construct(
        private DeploymentRecorder $recorder,
        private Dispatcher $bus,
        private Cache $cache,
    ) {}

    public function queue(Application $application, DeploymentTrigger $trigger, ?int $userId): Deployment
    {
        $lock = new UniqueLock($this->cache);

        if (! $lock->acquire(new DeployApplication($application->id))) {
            $waiting = Deployment::query()
                ->where('application_id', $application->id)
                ->where('status', DeploymentStatus::Queued->value)
                ->latest('id')
                ->first();

            if ($waiting !== null) {
                return $waiting;
            }

            // Lock with no waiting row (worker died, or queued before rows existed): queue
            // anyway. The lock expires on its own (see ExpiresUniqueLock); a double deploy is
            // harmless.
        }

        $this->closeOrphans($application);

        $deployment = $this->recorder->open($application, $trigger, $userId);

        // Straight to the queue: `dispatch()` would ask for the lock again and drop the job.
        $this->bus->dispatchToQueue(new DeployApplication($application->id, $userId, $deployment->id));

        return $deployment;
    }

    /**
     * Close `queued` rows no job will ever run.
     *
     * Only rows older than the lock's lifetime: a job just picked up releases its lock a moment
     * before it marks its row running, so a younger row may be that one.
     */
    private function closeOrphans(Application $application): void
    {
        $lifetime = (new DeployApplication($application->id))->uniqueFor();

        Deployment::query()
            ->where('application_id', $application->id)
            ->where('status', DeploymentStatus::Queued->value)
            ->where('created_at', '<', now()->subSeconds($lifetime))
            ->update([
                'status' => DeploymentStatus::Failed->value,
                'failed_step' => 'worker',
                'finished_at' => now(),
            ]);
    }
}
