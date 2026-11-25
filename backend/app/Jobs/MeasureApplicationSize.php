<?php

namespace App\Jobs;

use App\Exceptions\Server\ServerOperationException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\Application;
use App\Services\Server\Applications\FileBrowser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-measure a site after something changed its contents.
 *
 * Queued, not inline: `du` walks every inode, which would make deleting one file as slow as
 * counting the whole site.
 *
 * Unique per application *and* delayed, which together are the debounce: fifty deletes raise
 * fifty jobs, the first takes the lock, the rest are dropped, and the site is walked once
 * after the user stops. The delay also avoids measuring a directory mid-change.
 */
class MeasureApplicationSize implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    /** Long enough to cover a burst of edits, short enough to feel current. */
    public const DEBOUNCE_SECONDS = 60;

    public int $tries = 1;

    /** A site too big to count in this time is one where the number matters least. */
    public int $timeout = 300;

    /**
     * How many never-measured sites one page view may queue. Without the cap, opening the list
     * on a large server would queue one directory walk per site from a single request.
     */
    public const BACKFILL_LIMIT = 25;

    public function __construct(public int $applicationId) {}

    public function uniqueId(): string
    {
        return 'application-size-'.$this->applicationId;
    }

    /**
     * Queue a measurement for sites that have never had one, so sites created before sizes
     * were recorded fill in as the list is used.
     *
     * Safe to call on every request: the job is unique per application.
     *
     * @param  iterable<Application>  $applications
     * @return int how many were queued
     */
    public static function backfill(iterable $applications): int
    {
        $queued = 0;
        $skipped = 0;

        foreach ($applications as $application) {
            // Both, not either: the list shows "Not measured" when bytes are null, so a row
            // with a date but no figure must not be skipped.
            if ($application->directory_size_bytes !== null
                && $application->directory_size_updated_at !== null) {
                continue;
            }

            if ($queued >= self::BACKFILL_LIMIT) {
                $skipped++;

                continue;
            }

            self::dispatch($application->id)->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
            $queued++;
        }

        // Logged so a capped sweep is not mistaken for "everything is measured".
        if ($skipped > 0) {
            Log::channel('server-ops')->info('application size backfill capped', [
                'feature' => 'application',
                'op' => 'measure_size_backfill',
                'queued' => $queued,
                'skipped' => $skipped,
            ]);
        }

        return $queued;
    }

    public function handle(FileBrowser $files): void
    {
        $application = Application::find($this->applicationId);

        if ($application === null) {
            return;
        }

        try {
            $files->applicationSize($application, refresh: true);
        } catch (Throwable $e) {
            // Not worth failing the job over; the previous figure and its date stay as they were.
            //
            // Log the class and reference, not just the message: ServerOperationException has
            // no message, only a reference into the server-ops log.
            Log::channel('server-ops')->warning('could not measure application size', [
                'feature' => 'application',
                'op' => 'measure_size',
                'application' => $this->applicationId,
                'exception' => $e::class,
                // Grep the server-ops log for this id to find the command and stderr.
                'reference' => $e instanceof ServerOperationException ? $e->reference : null,
                'detail' => $e->getMessage(),
            ]);
        }
    }
}
