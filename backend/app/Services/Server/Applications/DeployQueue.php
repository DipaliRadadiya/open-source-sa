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
 * `DeployApplication` is unique until processing: while one deploy for a site
 * waits in the queue, Laravel silently drops every further dispatch — which
 * is the point, ten pushes should make one deploy. But every caller opened a
 * `queued` Deployment row *before* dispatching, so each dropped dispatch left
 * a row that no job would ever pick up, showing `queued` forever. Found on the
 * Apache test box: a push that arrived while a manual deploy was still waiting.
 *
 * So the lock is taken here first, with the same key and lifetime Laravel's
 * own check uses, and only a caller that gets it opens a row and queues the
 * job. One that does not is handed the deploy that is already waiting — which
 * fetches the branch tip when it runs, so the newer commit is deployed by it.
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

            // A lock with no waiting row: held by a deploy queued before rows
            // existed, or by one whose worker died. Queue anyway rather than
            // hand back nothing; the lock expires on its own (see
            // ExpiresUniqueLock), and running a deploy twice is harmless.
        }

        $this->closeOrphans($application);

        $deployment = $this->recorder->open($application, $trigger, $userId);

        // Straight to the queue: the lock is already ours, and going through
        // `dispatch()` would ask for it again and drop the job.
        $this->bus->dispatchToQueue(new DeployApplication($application->id, $userId, $deployment->id));

        return $deployment;
    }

    /**
     * Close `queued` rows no job will ever run.
     *
     * Rows left behind before this class existed, or by a job lost with its
     * worker. Only those older than the lock's own lifetime: a job that has
     * just been picked up releases its lock a moment before it marks its row
     * running, and a younger row may be exactly that one.
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
