<?php

namespace App\Services\Server\Settings;

use App\Enums\SecurityUpdateStatus;
use App\Models\SecurityUpdateRun;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes `security_update_runs`.
 *
 * One writer for the reason `InstallTracker` gives: the table's whole value is
 * being trustworthy about what is happening right now, and a second writer with
 * slightly different rules is how a row gets stranded at `running` while a
 * screen spins forever.
 */
class SecurityUpdateTracker
{
    /**
     * Grace on top of the job's timeout before a `running` row is disbelieved.
     *
     * The same shape as ExpiresUniqueLock's: long enough that a job still doing
     * its work is never declared dead, because calling a live upgrade abandoned
     * would invite the operator to start a second one behind apt's lock.
     */
    private const STALE_GRACE = 300;

    /**
     * Open a run, or return null because one is already open.
     *
     * The check and the insert are one transaction. Two clicks a moment apart
     * would otherwise both pass a check-then-insert and leave two rows at
     * `running` — apt's lock means only one of them could be doing anything, so
     * the second would be a row describing work that never happens.
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
     * Reconciles first, so a row whose worker died is never reported as in
     * flight — which would block every future run behind a job that is gone.
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
            // Read even on failure: an upgrade that installed four packages and
            // then broke on the fifth can still have left the box wanting a
            // restart, and not saying so is how a half-patched kernel stays
            // unbooted.
            'reboot_required_after' => $this->rebootRequired(),
        ]);
    }

    /**
     * Write the run's captured output without settling it.
     *
     * Called while the upgrade is still going, so the screen has something to
     * show — and so a run killed mid-flight still carries apt's last words.
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
     * The upgrade can restart the panel's own services, and the queue worker
     * executing it is one of them. When that happens nothing runs `failed()`,
     * nothing settles the row, and the screen polls a run that will never
     * advance. Age is the only evidence left.
     *
     * Scoped to rows still at `running` so a real reason is never clobbered.
     */
    public function reconcile(): void
    {
        // Read before writing. This runs on every settings page load, and an
        // unconditional UPDATE would take a write lock each time to change
        // nothing — on SQLite, which is the default here, that is a write lock
        // on a page somebody opens to look at a toggle.
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
                // Not 'unknown': the distinction the screen needs to draw is
                // "the upgrade reported a problem" versus "the thing running it
                // disappeared", and only the second means apt may have finished
                // the work anyway.
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
        // Guarded on `running` for the same reason reconcile() is: if age
        // already gave up on this row, the job finishing afterwards should not
        // quietly rewrite history with a second answer.
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
