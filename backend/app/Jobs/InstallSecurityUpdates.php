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
 * Install the waiting security updates, queued because it takes minutes.
 *
 * Unique with a constant id: apt takes a box-wide lock, so a second run would only wait behind
 * the first and repeat its work. `$tries = 1`: retrying a half-applied upgrade is a second upgrade.
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
        // Matched to the command's own budget: timing out first would leave apt mid-upgrade unwatched.
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
            // Without the row there is nothing to report to; do not run the upgrade blind.
            return;
        }

        $live = new SecurityUpdateOutput;

        $result = $updates->run(function (string $chunk) use ($live, $runs, $run): void {
            // Written once a kilobyte has built up, not per chunk: apt emits hundreds.
            if ($live->push($chunk)) {
                $runs->progress($run, $live->text());
            }
        });

        // The record comes from the finished result, not the stream: the progress callback may
        // never fire if all output arrives at exit. stderr is folded in because it holds the reason.
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
     * Without this the row stays at `running` until the tracker's age check gives up, and new runs
     * are refused meanwhile. Uses the same `worker` reason as that check.
     */
    public function failed(?Throwable $e): void
    {
        $run = SecurityUpdateRun::query()->find($this->runId);

        if ($run !== null) {
            app(SecurityUpdateTracker::class)->fail($run, 'worker');
        }
    }
}
