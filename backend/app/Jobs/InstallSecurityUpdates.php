<?php

namespace App\Jobs;

use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Models\SecurityUpdateRun;
use App\Services\ActivityLogger;
use App\Services\Server\Settings\SecurityUpdateOutput;
use App\Services\Server\Settings\SecurityUpdateRunner;
use App\Services\Server\Settings\SecurityUpdateTracker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Install the waiting security updates — minutes, not milliseconds, so it does
 * not hold a request open.
 *
 * Unique with a constant id: apt takes a box-wide lock, so a second run would
 * not race the first, it would sit behind it for ten minutes and then repeat
 * work that was already done.
 *
 * `$tries = 1`. A retry of a half-applied upgrade is not a retry, it is a second
 * upgrade, and the operator has the button.
 */
class InstallSecurityUpdates implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $runId, public ?int $actorId = null)
    {
        // Matched to the command's own budget rather than hardcoded: a job that
        // times out before the command does leaves apt mid-upgrade with nothing
        // watching it, which is the one outcome worth engineering against here.
        $this->timeout = (int) config('server.security_updates.timeout', 1800);
    }

    public function uniqueId(): string
    {
        return 'security-updates';
    }

    public function handle(
        SecurityUpdateRunner $updates,
        SecurityUpdateTracker $runs,
        ActivityLogger $log,
    ): void {
        $run = SecurityUpdateRun::query()->find($this->runId);

        if ($run === null) {
            // The row is the whole point of the job; without it there is
            // nothing to report progress to and nothing that would show a
            // result. Running the upgrade blind would be worse than not running
            // it.
            return;
        }

        $live = new SecurityUpdateOutput;

        $result = $updates->run(function (string $chunk) use ($live, $runs, $run): void {
            // Written when a kilobyte has built up, not per chunk: apt emits
            // hundreds of them and a write each would cost more than the
            // upgrade. This is what the screen watches while the upgrade runs.
            if ($live->push($chunk)) {
                $runs->progress($run, $live->text());
            }
        });

        // The record comes from the finished result, not from the stream.
        //
        // The callback is a progress feed and nothing guarantees it fired: it
        // is driven by how the process flushes, and a run whose output all
        // arrives at exit would leave the buffer empty. Building the stored
        // account out of it meant a successful upgrade could be recorded with
        // no output and no package count — which is most of what the row is
        // for. The result always holds everything the command said.
        //
        // stderr is folded in because that is where the reason is. Keeping only
        // stdout would store a narration that stops just before the problem.
        $final = new SecurityUpdateOutput;
        $final->push($result->output());

        $stderr = $result->result?->errorOutput() ?? '';

        if (trim($stderr) !== '') {
            $final->push("\n".$stderr);
        }

        $text = $final->text();
        $runs->progress($run, $text);

        if ($result->failed()) {
            $reason = $updates->classify($result, $text);

            $runs->fail($run, $reason, $result->reference, $result->exitCode());
            $log->log('setting.security_updates_failed', null, ['reason' => $reason], actor: $this->actor());

            return;
        }

        $packages = $final->packagesUpgraded();

        $runs->succeed(
            $run,
            $result->exitCode(),
            $packages,
            // Read after the upgrade, because that is when the flag appears.
            is_file((string) config('server.reboot_required_file', '/var/run/reboot-required')),
        );

        $log->log('setting.security_updates_installed', null, [
            'packages' => $packages,
        ], actor: $this->actor());
    }

    /**
     * The worker died, or the job was released and exhausted its one try.
     *
     * Without this the row sits at `running` until the tracker's age check gives
     * up on it half an hour later, and every attempt to start another run is
     * refused in the meantime. Same `worker` reason the age check uses: what the
     * screen needs to say is "the thing running this disappeared — apt may have
     * finished the work anyway", and that is true in both cases.
     */
    public function failed(?Throwable $e): void
    {
        $run = SecurityUpdateRun::query()->find($this->runId);

        if ($run !== null) {
            app(SecurityUpdateTracker::class)->fail($run, 'worker');
        }
    }
}
