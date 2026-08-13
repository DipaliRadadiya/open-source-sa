<?php

namespace App\Services\Runtime;

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use Illuminate\Support\Collection;

/**
 * The only thing that writes `runtime_installs`.
 *
 * One writer because the table's whole value is being trustworthy about what
 * is happening right now: a second writer with slightly different rules is how
 * a row gets stranded at `installing` and a screen spins forever.
 */
class InstallTracker
{
    /**
     * Record that an install has been queued.
     *
     * Called from the controller *before* dispatch, deliberately. Started
     * inside the job instead, there is a window between the 202 and the worker
     * picking the job up where the install exists but nothing can see it —
     * which is the exact blindness this table is here to remove.
     */
    public function start(string $runtime, string $version, ?string $extension = null): RuntimeInstall
    {
        return RuntimeInstall::query()->updateOrCreate(
            ['runtime' => $runtime, 'version' => $version, 'extension' => (string) $extension],
            [
                'status' => InstallStatus::Installing,
                // A retry clears the last failure rather than stacking on it.
                'reason' => null,
                'reference' => null,
                // Likewise the last run's progress: showing the previous
                // attempt's output under a fresh spinner would have the
                // operator reading a failure that has already been retried.
                'current_step' => null,
                'output' => null,
                'started_at' => now(),
                'finished_at' => null,
            ],
        );
    }

    /**
     * The same row, for a purge rather than an install.
     *
     * Shares the table deliberately: the screen asks one question — "is
     * anything happening to this version?" — and two tables would mean two
     * answers, free to disagree about a version being installed and removed
     * at once.
     */
    public function startRemoval(string $runtime, string $version): RuntimeInstall
    {
        return tap($this->start($runtime, $version), fn (RuntimeInstall $row) => $row
            ->forceFill(['status' => InstallStatus::Removing])
            ->save());
    }

    /** The in-flight row for one install, so a job can report progress to it. */
    public function current(string $runtime, string $version, ?string $extension = null): ?RuntimeInstall
    {
        return $this->query($runtime, $version, $extension)->first();
    }

    /**
     * Success removes the row: the runtime can now be seen on disk, and that
     * is the answer everything else already reads.
     */
    public function succeed(string $runtime, string $version, ?string $extension = null): void
    {
        $this->query($runtime, $version, $extension)->delete();
    }

    public function fail(string $runtime, string $version, ?string $extension, string $reason, ?string $reference = null): void
    {
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
     * Only touches rows still at `installing`: by the time a `failed()` hook
     * runs the job may already have recorded a real reason, and "the worker
     * died" would overwrite the more useful one.
     */
    public function abandon(string $runtime, string $version, ?string $extension = null): void
    {
        $this->query($runtime, $version, $extension)
            ->where('status', InstallStatus::Installing->value)
            ->update([
                'status' => InstallStatus::Failed,
                'reason' => 'worker',
                'finished_at' => now(),
            ]);
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

    private function query(string $runtime, string $version, ?string $extension)
    {
        return RuntimeInstall::query()
            ->where('runtime', $runtime)
            ->where('version', $version)
            ->where('extension', (string) $extension);
    }
}
