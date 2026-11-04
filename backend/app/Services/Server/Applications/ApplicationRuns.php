<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Models\ApplicationRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the record of one install or one container redeploy (D3/D5).
 *
 * Opened `queued` by whoever dispatches the job, so Background jobs and the
 * app screen show it in the seconds before a worker picks it up, then moved on
 * by the job. Every transition out of `queued`/`running` is conditional on the
 * row still being there, so a row closed as stranded is never reopened by a
 * job that turns up late.
 */
class ApplicationRuns
{
    /** Newest rows kept per application and kind. */
    public const KEEP = 20;

    public function open(Application $application, string $kind, ?int $userId): ApplicationRun
    {
        $run = ApplicationRun::create([
            'application_id' => $application->id,
            'user_id' => $userId,
            'kind' => $kind,
            'status' => ApplicationRun::QUEUED,
        ]);

        $this->prune($application, $kind);

        return $run;
    }

    /**
     * Take a queued row to `running`. False when it is no longer queued —
     * closed as stranded, or already taken by another run of the same job.
     */
    public function start(ApplicationRun $run): bool
    {
        $updated = ApplicationRun::whereKey($run->id)
            ->where('status', ApplicationRun::QUEUED)
            ->update(['status' => ApplicationRun::RUNNING, 'started_at' => now(), 'updated_at' => now()]);

        $run->refresh();

        return $updated === 1;
    }

    /**
     * The install job's way in: it is dispatched from more than one place and
     * may be one queued before this record existed, so it adopts the newest
     * row still open for its site, or opens one.
     */
    public function startInstall(Application $application, ?int $userId): ApplicationRun
    {
        $run = ApplicationRun::query()
            ->where('application_id', $application->id)
            ->where('kind', ApplicationRun::INSTALL)
            ->whereIn('status', ApplicationRun::IN_FLIGHT)
            ->latest('id')
            ->first()
            ?? $this->open($application, ApplicationRun::INSTALL, $userId);

        $run->forceFill([
            'status' => ApplicationRun::RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'failed_step' => null,
            'failed_reason' => null,
            'reference' => null,
        ])->save();

        // An older row still open for this site belongs to a dispatch that
        // never ran (the job is unique per site, so it cannot be running): left
        // alone it would read "waiting" in Background jobs once this one ends.
        ApplicationRun::query()
            ->where('application_id', $application->id)
            ->where('kind', ApplicationRun::INSTALL)
            ->whereIn('status', ApplicationRun::IN_FLIGHT)
            ->whereKeyNot($run->id)
            ->update(['status' => ApplicationRun::FAILED, 'failed_step' => 'worker', 'finished_at' => now(), 'updated_at' => now()]);

        return $run;
    }

    public function succeed(ApplicationRun $run): void
    {
        $this->close($run, ['status' => ApplicationRun::SUCCEEDED]);
    }

    public function fail(ApplicationRun $run, ?string $step, ?string $reason = null, ?string $reference = null): void
    {
        $this->close($run, [
            'status' => ApplicationRun::FAILED,
            'failed_step' => $step,
            'failed_reason' => $reason,
            'reference' => $reference,
        ]);
    }

    /**
     * Close whatever is still open for this site and kind — for the paths that
     * have only the application to go on: a job's `failed()`, a stranded
     * install.
     */
    public function failOpen(int $applicationId, string $kind, string $step, ?string $reference): int
    {
        return ApplicationRun::query()
            ->where('application_id', $applicationId)
            ->where('kind', $kind)
            ->whereIn('status', ApplicationRun::IN_FLIGHT)
            ->update([
                'status' => ApplicationRun::FAILED,
                'failed_step' => $step,
                'reference' => $reference,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** The redeploy of this site still under way, if any. Stranded ones are not. */
    public function redeployInFlight(Application $application): ?ApplicationRun
    {
        return ApplicationRun::query()
            ->where('application_id', $application->id)
            ->where('kind', ApplicationRun::CONTAINER_REDEPLOY)
            ->inFlight()
            ->latest('id')
            ->first();
    }

    /**
     * Mark failed every redeploy whose job is provably gone. Returns how many.
     * Run by `applications:close-stranded` and before every new redeploy.
     */
    public function closeStranded(?Application $application = null): int
    {
        $closed = 0;

        ApplicationRun::query()
            ->where('kind', ApplicationRun::CONTAINER_REDEPLOY)
            ->whereIn('status', ApplicationRun::IN_FLIGHT)
            ->when($application !== null, fn ($query) => $query->where('application_id', $application->id))
            ->get()
            ->filter(fn (ApplicationRun $run) => $run->isStranded())
            ->each(function (ApplicationRun $run) use (&$closed) {
                $reference = (string) Str::uuid();

                $updated = ApplicationRun::whereKey($run->id)
                    ->where('status', $run->status)
                    ->update([
                        'status' => ApplicationRun::FAILED,
                        'failed_step' => 'worker',
                        'reference' => $reference,
                        'finished_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($updated === 1) {
                    $closed++;

                    Log::error('container redeploy never finished; marked failed', [
                        'reference' => $reference,
                        'application_id' => $run->application_id,
                        'run_id' => $run->id,
                    ]);
                }
            });

        return $closed;
    }

    /** @param array<string, mixed> $values */
    private function close(ApplicationRun $run, array $values): void
    {
        ApplicationRun::whereKey($run->id)
            ->whereIn('status', ApplicationRun::IN_FLIGHT)
            ->update([...$values, 'finished_at' => now(), 'updated_at' => now()]);

        $run->refresh();
    }

    private function prune(Application $application, string $kind): void
    {
        $cutoff = ApplicationRun::query()
            ->where('application_id', $application->id)
            ->where('kind', $kind)
            ->orderByDesc('id')
            ->skip(self::KEEP)
            ->take(1)
            ->value('id');

        if ($cutoff !== null) {
            ApplicationRun::query()
                ->where('application_id', $application->id)
                ->where('kind', $kind)
                ->where('id', '<=', $cutoff)
                ->delete();
        }
    }
}
