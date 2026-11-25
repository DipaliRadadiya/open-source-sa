<?php

namespace App\Services\Server\Settings;

use App\Enums\SecurityUpdateStatus;
use App\Models\SecurityUpdateRun;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes `security_update_runs`.
 *
 * One writer, as with `InstallTracker`: a second writer with different rules is how a row gets
 * stranded at `running` while a screen spins forever.
 */
class SecurityUpdateTracker
{
    /**
     * Grace on top of the job's timeout before a `running` row is disbelieved.
     *
     * Long enough that a live upgrade is never declared dead, which would invite a second one
     * behind apt's lock.
     */
    private const STALE_GRACE = 300;

    /**
     * Open a run, or return null because one is already open.
     *
     * Check and insert share one transaction, so two quick clicks cannot leave two `running` rows.
     */
    public function start(?int $userId): ?SecurityUpdateRun
    {
        return DB::transaction(function () use ($userId): ?SecurityUpdateRun {
            if ($this->inFlight(lock: true) !== null) {
                return null;
            }

            return SecurityUpdateRun::query()->create([
                'user_id' => $userId,
                'status' => SecurityUpdateStatus::Running,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * The run that is happening now, if there is one.
     *
     * Reconciles first, so a row whose worker died never blocks future runs.
     */
    public function inFlight(bool $lock = false): ?SecurityUpdateRun
    {
        if (! $lock) {
            $this->reconcile();
        }

        $query = SecurityUpdateRun::query()
            ->where('status', SecurityUpdateStatus::Running->value);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->latest('started_at')->first();
    }

    /** The most recent run in any state, reconciled. */
    public function latest(): ?SecurityUpdateRun
    {
        $this->reconcile();

        return SecurityUpdateRun::query()->latest('started_at')->first();
    }

    public function succeed(
        SecurityUpdateRun $run,
        ?int $exitCode,
        ?int $packagesUpgraded,
        bool $rebootRequired,
    ): void {
        $this->settle($run, [
            'status' => SecurityUpdateStatus::Succeeded->value,
            'reason' => null,
            'exit_code' => $exitCode,
            'packages_upgraded' => $packagesUpgraded,
            'reboot_required_after' => $rebootRequired,
        ]);
    }

    public function fail(
        SecurityUpdateRun $run,
        string $reason,
        ?string $reference = null,
        ?int $exitCode = null,
    ): void {
        $this->settle($run, [
            'status' => SecurityUpdateStatus::Failed->value,
            'reason' => $reason,
            'reference' => $reference,
            'exit_code' => $exitCode,
            // Read even on failure: a half-finished upgrade can still need a reboot.
            'reboot_required_after' => $this->rebootRequired(),
        ]);
    }

    /**
     * Write the run's captured output without settling it, so a run killed mid-flight still has
     * apt's last words.
     */
    public function progress(SecurityUpdateRun $run, string $output): void
    {
        SecurityUpdateRun::query()
            ->whereKey($run->getKey())
            ->where('status', SecurityUpdateStatus::Running->value)
            ->update(['output' => $output, 'updated_at' => now()]);
    }

    /**
     * Disbelieve a `running` row that is older than any job could be.
     *
     * The upgrade can restart the queue worker executing it, so nothing settles the row and age is
     * the only evidence left. Only rows still at `running` are touched.
     */
    public function reconcile(): void
    {
        // Read before writing: this runs on every settings page load, and an unconditional UPDATE
        // would take a write lock (SQLite) each time to change nothing.
        $stale = SecurityUpdateRun::query()
            ->where('status', SecurityUpdateStatus::Running->value)
            ->where('started_at', '<', now()->subSeconds($this->staleAfter()))
            ->pluck('id');

        if ($stale->isEmpty()) {
            return;
        }

        SecurityUpdateRun::query()
            ->whereIn('id', $stale)
            ->where('status', SecurityUpdateStatus::Running->value)
            ->update([
                'status' => SecurityUpdateStatus::Failed->value,
                // Not 'unknown': `worker` means the runner disappeared, so apt may have finished
                // anyway.
                'reason' => 'worker',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function staleAfter(): int
    {
        return (int) config('server.security_updates.timeout', 1800) + self::STALE_GRACE;
    }

    /** @param  array<string, mixed>  $attributes */
    private function settle(SecurityUpdateRun $run, array $attributes): void
    {
        // Guarded on `running` like reconcile(): a job finishing after age gave up must not
        // rewrite it.
        SecurityUpdateRun::query()
            ->whereKey($run->getKey())
            ->where('status', SecurityUpdateStatus::Running->value)
            ->update([...$attributes, 'finished_at' => now(), 'updated_at' => now()]);
    }

    private function rebootRequired(): bool
    {
        return is_file((string) config('server.reboot_required_file', '/var/run/reboot-required'));
    }
}
