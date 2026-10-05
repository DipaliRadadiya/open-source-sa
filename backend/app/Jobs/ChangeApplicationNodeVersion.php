<?php

namespace App\Jobs;

use App\Actions\Server\Application\ChangeNodeVersion;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\Application;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Switch a site's Node version in the background (junior re-test #12).
 *
 * Queued because the switch waits for the application to answer, and a
 * failed one waits again on the way back: up to a minute, which a request
 * should not hold. Unique per application, so two switches of the same site
 * cannot run into each other's units.
 */
class ChangeApplicationNodeVersion implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $applicationId, public string $target) {}

    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    public function handle(ChangeNodeVersion $change): void
    {
        $application = Application::query()->find($this->applicationId);

        // Deleted while queued, or the request was superseded.
        if ($application === null || $application->node_version_target !== $this->target) {
            return;
        }

        $change->execute($application, $this->target);
    }

    /**
     * The worker died or timed out mid-switch. Say so on the row rather than
     * leaving "switching" up forever, which would also block the next try.
     */
    public function failed(?Throwable $exception): void
    {
        Application::query()
            ->whereKey($this->applicationId)
            ->where('node_version_target', $this->target)
            ->whereNull('node_version_failed_reason')
            ->update(['node_version_failed_reason' => 'worker']);
    }
}
