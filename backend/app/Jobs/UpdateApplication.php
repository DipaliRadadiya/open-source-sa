<?php

namespace App\Jobs;

use App\Enums\ApplicationUpdateStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\ApplicationUpdate;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationUpdates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Update one site's own software (one-click update, v7 parity).
 *
 * Unique per application and tried once: two updates of the same code tree
 * would overwrite each other's files halfway, and a retry would start from
 * whatever the failed attempt left — the user decides whether to try again.
 */
class UpdateApplication implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    /** Moodle's package is 75 MB and its upgrade walks hundreds of tables. */
    public int $timeout = 1800;

    public function __construct(
        public int $updateId,
        public int $applicationId,
    ) {}

    public function uniqueId(): string
    {
        return 'app-update-'.$this->applicationId;
    }

    public function handle(ApplicationUpdates $updates, ActivityLogger $activity): void
    {
        $update = ApplicationUpdate::with(['application.systemUser', 'user'])->find($this->updateId);

        if ($update === null || $update->status !== ApplicationUpdateStatus::Pending || $update->application === null) {
            return;
        }

        $application = $update->application;
        $update->update(['status' => ApplicationUpdateStatus::Running, 'started_at' => now()]);

        try {
            $updater = $updates->updaterFor($application);

            if ($updater === null) {
                throw new ProvisioningFailedException('start', (string) Str::uuid(), 'unsupported');
            }

            $updater->update($application, (string) $update->from_version, (string) $update->to_version);

            // The tools report success on their own terms; the version the
            // site's files now carry is the one thing that says it worked.
            $now = $updater->installedVersion($application);

            if ($now === null || version_compare($now, (string) $update->to_version, '<')) {
                throw new ProvisioningFailedException('verify', (string) Str::uuid(), 'version_unchanged');
            }

            $update->update([
                'status' => ApplicationUpdateStatus::Completed,
                'to_version' => $now,
                'finished_at' => now(),
            ]);

            $activity->log('application.updated', $application, [
                'name' => $application->name,
                'from' => $update->from_version,
                'to' => $now,
            ], $update->user);
        } catch (Throwable $e) {
            [$reason, $step, $reference] = $e instanceof ProvisioningFailedException
                ? [$e->reason ?? 'step_failed', $e->step, $e->reference]
                : ['failed', null, (string) Str::uuid()];

            Log::channel('server-ops')->error('application update failed', [
                'feature' => 'application',
                'op' => 'update',
                'application' => $application->id,
                'update' => $update->id,
                'reason' => $reason,
                'step' => $step,
                'reference' => $reference,
                'detail' => $e->getMessage(),
            ]);

            $update->update([
                'status' => ApplicationUpdateStatus::Failed,
                'reason' => $reason,
                'step' => $step,
                'reference' => $reference,
                'finished_at' => now(),
            ]);

            $activity->log('application.update_failed', $application, [
                'name' => $application->name,
                'to' => $update->to_version,
            ], $update->user);
        }
    }

    /** The worker died or timed out mid-update: say so instead of "running" forever. */
    public function failed(?Throwable $e): void
    {
        ApplicationUpdate::query()
            ->whereKey($this->updateId)
            ->whereIn('status', ApplicationUpdateStatus::active())
            ->update([
                'status' => ApplicationUpdateStatus::Failed->value,
                'reason' => 'worker',
                'finished_at' => now(),
            ]);
    }
}
