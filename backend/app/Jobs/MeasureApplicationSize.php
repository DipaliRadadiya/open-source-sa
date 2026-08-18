<?php

namespace App\Jobs;

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
 * Queued and delayed rather than done inline, because `du` walks every inode:
 * on a WordPress install that is forty thousand of them, and doing it in the
 * request would make deleting one file as slow as counting the whole site.
 *
 * Unique per application *and* delayed, which together are the debounce.
 * Deleting fifty files raises fifty of these; the first one takes the lock and
 * the other forty-nine are dropped, so the site is walked once, shortly after
 * the person stops working, rather than once per click.
 *
 * The delay is the point, not an accident of scheduling — running immediately
 * would measure a directory somebody is still in the middle of changing.
 */
class MeasureApplicationSize implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    /** Long enough to cover a burst of edits, short enough to feel current. */
    public const DEBOUNCE_SECONDS = 60;

    public int $tries = 1;

    /**
     * A site large enough to take longer than this to count is one where the
     * number matters least and the disk cost matters most.
     */
    public int $timeout = 300;

    /**
     * How many never-measured sites one page view may queue.
     *
     * The cap is the whole point. Opening the sites list on a server with
     * three hundred sites would otherwise queue three hundred directory walks
     * off a single request, on the machine those sites are served from. At
     * this rate a large server fills in over a few visits instead, which is
     * the correct trade: nobody is waiting on the number.
     */
    public const BACKFILL_LIMIT = 25;

    public function __construct(public int $applicationId) {}

    public function uniqueId(): string
    {
        return 'application-size-'.$this->applicationId;
    }

    /**
     * Queue a measurement for sites that have never had one.
     *
     * A size is written when a site is provisioned, deployed, or browsed —
     * which leaves every site created before that was true showing "Not
     * measured" forever. Rather than a migration nobody can run twice or a
     * command nobody remembers, the list backfills itself as it is used.
     *
     * Safe to call on every request: the job is unique per application, so a
     * hundred page views raise one walk per site rather than a hundred.
     *
     * @param  iterable<Application>  $applications
     * @return int how many were queued
     */
    public static function backfill(iterable $applications): int
    {
        $queued = 0;
        $skipped = 0;

        foreach ($applications as $application) {
            if ($application->directory_size_updated_at !== null) {
                continue;
            }

            if ($queued >= self::BACKFILL_LIMIT) {
                $skipped++;

                continue;
            }

            self::dispatch($application->id)->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
            $queued++;
        }

        // Said out loud rather than truncated in silence — a capped sweep that
        // reports nothing reads as "everything is measured" when it is not.
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
            // A size that could not be measured is not worth failing a job
            // over, and never worth interrupting whatever the user is doing.
            // The previous figure and its date stay as they were, which is
            // honest: it says when it was last actually true.
            Log::channel('server-ops')->warning('could not measure application size', [
                'feature' => 'application',
                'op' => 'measure_size',
                'application' => $this->applicationId,
                'detail' => $e->getMessage(),
            ]);
        }
    }
}
