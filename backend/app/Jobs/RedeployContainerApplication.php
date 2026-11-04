<?php

namespace App\Jobs;

use App\Actions\Server\Application\PullContainerImage;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\Concerns\TracksActor;
use App\Models\Application;
use App\Models\ApplicationRun;
use App\Services\Server\Applications\ApplicationRuns;
use App\Services\Server\Applications\ProvisioningBudget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Pull and redeploy, on the queue (D5): `compose pull`, then `compose up` on
 * the new image, exactly as the endpoint used to do inside the request.
 *
 * Not a unique job: the run row is the guard. `StartContainerRedeploy` opens
 * at most one per site, and `start()` takes it from `queued` only once, so a
 * duplicate delivery or a row already closed as stranded does nothing.
 *
 * `$tries = 1` like provisioning: an automatic second pull would repeat a
 * failure the user needs to see, and they can press the button again.
 */
class RedeployContainerApplication implements ShouldQueue
{
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $runId, public ?int $actorId = null)
    {
        $this->timeout = app(ProvisioningBudget::class)->forContainerRedeploy();
    }

    public function handle(ApplicationRuns $runs, PullContainerImage $pull): void
    {
        $run = ApplicationRun::find($this->runId);

        // Deleted with its site, or closed as stranded before a worker got here.
        if ($run === null || ! $runs->start($run)) {
            return;
        }

        $application = Application::with(['systemUser', 'registry'])->find($run->application_id);

        if ($application === null) {
            return;
        }

        try {
            $pull->execute($application);

            $runs->succeed($run);
        } catch (ProvisioningFailedException $e) {
            // Already on the application too (`PullContainerImage`), which is
            // what the failure card reads.
            $runs->fail($run, $e->step, $e->reason, $e->reference);
        } catch (HttpExceptionInterface $e) {
            // Paused, or no longer active, between the click and the worker.
            $runs->fail($run, 'container_not_running');
        }
    }

    /**
     * The job itself died: timed out, worker killed, or something that is not
     * a pull failure. Leave the run honest rather than "redeploying" forever,
     * and the reason in the log under the reference the screen shows.
     */
    public function failed(?Throwable $e): void
    {
        $reference = (string) Str::uuid();

        Log::error('container redeploy job died at the worker level', [
            'reference' => $reference,
            'run_id' => $this->runId,
            'exception' => $e ? $e::class.': '.$e->getMessage() : null,
        ]);

        $run = ApplicationRun::find($this->runId);

        // Already closed — by the job itself, or as stranded.
        if ($run === null || ! in_array($run->status, ApplicationRun::IN_FLIGHT, true)) {
            return;
        }

        app(ApplicationRuns::class)->fail($run, 'worker', null, $reference);

        Application::whereKey($run->application_id)->update([
            'failed_step' => 'worker',
            'failed_reason' => null,
        ]);
    }
}
