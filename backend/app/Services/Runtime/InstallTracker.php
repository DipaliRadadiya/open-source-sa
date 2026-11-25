<?php

namespace App\Services\Runtime;

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use App\Support\ProbeCache;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The only thing that writes `runtime_installs`.
 *
 * One writer, so rows follow one set of rules and none is stranded at `installing`.
 */
class InstallTracker
{
    /**
     * Record that an install has been queued.
     *
     * Called from the controller *before* dispatch, so the install is visible
     * between the 202 and the worker picking the job up.
     */
    public function start(
        string $runtime,
        string $version,
        ?string $extension = null,
        ?string $initialStep = null,
        ?bool $wasAbsent = null,
    ): RuntimeInstall {
        $identity = ['runtime' => $runtime, 'version' => $version, 'extension' => (string) $extension];
        $now = now();

        // Upsert, not `updateOrCreate()`: two simultaneous requests would race on the unique index.
        RuntimeInstall::query()->upsert(
            [[
                ...$identity,
                'status' => InstallStatus::Installing->value,
                // A retry clears the last failure rather than stacking on it.
                'reason' => null,
                'reference' => null,
                // Likewise the last run's progress, so a retry does not show old output.
                'current_step' => $initialStep,
                'output' => null,
                'started_at' => $now,
                // Null until a worker picks the job up, so a queued install is not
                // shown as running. begin() stamps it.
                'picked_up_at' => null,
                'finished_at' => null,
                'was_absent' => $wasAbsent,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['runtime', 'version', 'extension'],
            // `was_absent` is deliberately not updated: it records what was true when the
            // install was first asked for, and a retry would see the half-installed package.
            ['status', 'reason', 'reference', 'current_step', 'output', 'started_at', 'picked_up_at', 'finished_at', 'updated_at'],
        );

        $row = $this->query($runtime, $version, $extension)->firstOrFail();

        // Installs change what the cached server screens report.
        ProbeCache::flush();

        // The upsert skips `was_absent` on an existing row; fill it if it was never recorded.
        if ($row->was_absent === null && $wasAbsent !== null) {
            $row->forceFill(['was_absent' => $wasAbsent])->save();
        }

        return $row;
    }

    /**
     * The same row, for a purge rather than an install.
     *
     * Shares the table so one row answers "is anything happening to this version?".
     */
    public function startRemoval(string $runtime, string $version): RuntimeInstall
    {
        return tap($this->start($runtime, $version), fn (RuntimeInstall $row) => $row
            ->forceFill(['status' => InstallStatus::Removing])
            ->save());
    }

    /**
     * A worker has picked the job up: the install leaves the queue.
     * Returns the row, as current() does, for the job to report progress to.
     */
    public function begin(string $runtime, string $version, ?string $extension = null): ?RuntimeInstall
    {
        $this->query($runtime, $version, $extension)
            ->whereIn('status', [InstallStatus::Installing->value, InstallStatus::Removing->value])
            ->whereNull('picked_up_at')
            ->update(['picked_up_at' => now()]);

        return $this->current($runtime, $version, $extension);
    }

    /** The in-flight row for one install, so a job can report progress to it. */
    public function current(string $runtime, string $version, ?string $extension = null): ?RuntimeInstall
    {
        return $this->query($runtime, $version, $extension)->first();
    }

    /**
     * Success removes the row: the runtime is now visible on disk.
     */
    public function succeed(string $runtime, string $version, ?string $extension = null): void
    {
        $this->query($runtime, $version, $extension)->delete();
        ProbeCache::flush();
    }

    public function fail(string $runtime, string $version, ?string $extension, string $reason, ?string $reference = null): void
    {
        ProbeCache::flush();

        $this->query($runtime, $version, $extension)->update([
            'status' => InstallStatus::Failed,
            'reason' => $reason,
            'reference' => $reference,
            'finished_at' => now(),
        ]);
    }

    /**
     * Mark a stranded row failed because the worker itself died.
     *
     * Only touches rows still at `installing`, so a reason the job already recorded is kept.
     */
    public function abandon(string $runtime, string $version, ?string $extension = null, ?Throwable $why = null): void
    {
        $this->abandonStatus($runtime, $version, $extension, InstallStatus::Installing, self::timedOut($why) ? 'timed_out' : 'worker');
    }

    /**
     * The job ran out of time, as opposed to the worker dying (e.g. an apt mirror
     * that never answers).
     */
    private static function timedOut(?Throwable $why): bool
    {
        return $why instanceof TimeoutExceededException
            || ($why instanceof MaxAttemptsExceededException && str_contains(strtolower($why->getMessage()), 'timed out'));
    }

    /** Mark a stranded removal failed without overwriting a recorded failure. */
    public function abandonRemoval(string $runtime, string $version, ?Throwable $why = null): void
    {
        $this->abandonStatus($runtime, $version, null, InstallStatus::Removing, self::timedOut($why) ? 'remove_timed_out' : 'remove_worker');
    }

    /**
     * In-flight and failed versions for a runtime, keyed by version.
     *
     * @return Collection<string, RuntimeInstall>
     */
    public function versions(string $runtime): Collection
    {
        return RuntimeInstall::query()
            ->where('runtime', $runtime)
            ->where('extension', '')
            ->get()
            ->keyBy('version');
    }

    /**
     * Extension rows for one version, keyed by extension name.
     *
     * @return Collection<string, RuntimeInstall>
     */
    public function extensions(string $runtime, string $version): Collection
    {
        return RuntimeInstall::query()
            ->where('runtime', $runtime)
            ->where('version', $version)
            ->where('extension', '!=', '')
            ->get()
            ->keyBy('extension');
    }

    private function abandonStatus(string $runtime, string $version, ?string $extension, InstallStatus $status, string $reason): void
    {
        $this->query($runtime, $version, $extension)
            ->where('status', $status->value)
            ->update([
                'status' => InstallStatus::Failed,
                'reason' => $reason,
                'finished_at' => now(),
            ]);
    }

    private function query(string $runtime, string $version, ?string $extension)
    {
        return RuntimeInstall::query()
            ->where('runtime', $runtime)
            ->where('version', $version)
            ->where('extension', (string) $extension);
    }
}
